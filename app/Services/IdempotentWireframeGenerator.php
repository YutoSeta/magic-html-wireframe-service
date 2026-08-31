<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyStoreUnavailableException;
use App\Services\Contracts\ReportsWireframeTelemetry;
use App\Services\Contracts\WireframeGenerator;
use App\Support\CanonicalJson;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final class IdempotentWireframeGenerator
{
    private Repository $cache;

    public function __construct(
        CacheManager $cache,
        private readonly WireframeGenerator $generator,
    ) {
        $store = config('wireframe.idempotency.store');
        $this->cache = $cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    /**
     * @param  array<string,mixed>  $siteAst
     * @param  array<string,mixed>  $brief
     * @return array{wireframe:array<string,mixed>,telemetry:array<string,mixed>|null,replayed:bool}
     */
    public function generate(
        string $idempotencyKey,
        string $requestBytes,
        string $contractVersion,
        array $siteAst,
        array $brief,
        string $locale,
        int $wireframeAstVersion = 1,
    ): array {
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', "wireframes\0{$contractVersion}\0{$requestBytes}");
        $claim = $this->claim($keyHash, $requestHash);

        if ($claim['wireframe'] !== null) {
            return [
                'wireframe' => $claim['wireframe'],
                'telemetry' => $this->replayTelemetry($claim['telemetry']),
                'replayed' => true,
            ];
        }

        try {
            $wireframe = $this->generator->generate($siteAst, $brief, $locale, $wireframeAstVersion);
        } catch (Throwable $exception) {
            $this->abandon($keyHash, $requestHash);

            throw $exception;
        }

        $telemetry = $this->generator instanceof ReportsWireframeTelemetry
            ? $this->generator->telemetry()
            : null;

        return $this->complete($keyHash, $requestHash, $wireframe, $telemetry, $wireframeAstVersion);
    }

    /** @return array{wireframe:array<string,mixed>|null,telemetry:array<string,mixed>|null} */
    private function claim(string $keyHash, string $requestHash): array
    {
        return $this->withLock($keyHash, function () use ($keyHash, $requestHash): array {
            $record = $this->record($keyHash);
            if ($record !== null) {
                $this->assertSameRequest($record, $requestHash);
                if (($record['state'] ?? null) === 'completed') {
                    $response = $this->completedResponse($record);
                    if ($response !== null) {
                        return $this->restoreResponse($response);
                    }

                    throw new IdempotencyStoreUnavailableException;
                }

                throw new IdempotencyInProgressException;
            }

            $stored = $this->cache->put($this->recordKey($keyHash), [
                'version' => 1,
                'operation' => 'wireframes.generate',
                'request_hash' => $requestHash,
                'state' => 'processing',
            ], now()->addSeconds((int) config('wireframe.idempotency.processing_ttl_seconds', 3600)));
            if (! $stored) {
                throw new IdempotencyStoreUnavailableException;
            }

            return ['wireframe' => null, 'telemetry' => null];
        });
    }

    /**
     * @param  array<string,mixed>  $wireframe
     * @param  array<string,mixed>|null  $telemetry
     * @return array{wireframe:array<string,mixed>,telemetry:array<string,mixed>|null,replayed:bool}
     */
    private function complete(string $keyHash, string $requestHash, array $wireframe, ?array $telemetry, int $wireframeAstVersion): array
    {
        return $this->withLock($keyHash, function () use ($keyHash, $requestHash, $wireframe, $telemetry, $wireframeAstVersion): array {
            $record = $this->record($keyHash);
            if ($record === null) {
                throw new IdempotencyInProgressException('The Idempotency-Key claim expired before its response could be recorded.');
            }

            $this->assertSameRequest($record, $requestHash);
            if (($record['state'] ?? null) === 'completed') {
                $response = $this->completedResponse($record);
                if ($response === null) {
                    throw new IdempotencyStoreUnavailableException;
                }
                $restored = $this->restoreResponse($response);

                return [
                    'wireframe' => $restored['wireframe'],
                    'telemetry' => $this->replayTelemetry($restored['telemetry']),
                    'replayed' => true,
                ];
            }
            if (($record['state'] ?? null) !== 'processing') {
                throw new IdempotencyInProgressException;
            }

            $response = ['wireframe' => $wireframe];
            if ($telemetry !== null) {
                $response['telemetry'] = $telemetry;
            }
            if ($wireframeAstVersion === 2) {
                $stored = $this->cache->put($this->recordKey($keyHash), [
                    'version' => 2,
                    'operation' => 'wireframes.generate',
                    'request_hash' => $requestHash,
                    'state' => 'completed',
                    'response_encrypted' => $this->encryptResponse($response),
                ], now()->addSeconds(max(60, (int) config('wireframe.idempotency.v2_response_ttl_seconds', 86400))));
            } else {
                $stored = $this->cache->forever($this->recordKey($keyHash), [
                    'version' => 1,
                    'operation' => 'wireframes.generate',
                    'request_hash' => $requestHash,
                    'state' => 'completed',
                    'response' => $response,
                ]);
            }
            if (! $stored) {
                throw new IdempotencyStoreUnavailableException;
            }

            return [
                'wireframe' => $wireframe,
                'telemetry' => $this->generationTelemetry($telemetry),
                'replayed' => false,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $response
     * @return array{wireframe:array<string,mixed>,telemetry:array<string,mixed>|null}
     */
    private function restoreResponse(array $response): array
    {
        if (is_array($response['wireframe'] ?? null)) {
            return [
                'wireframe' => $response['wireframe'],
                'telemetry' => is_array($response['telemetry'] ?? null) ? $response['telemetry'] : null,
            ];
        }

        return ['wireframe' => $response, 'telemetry' => null];
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function completedResponse(array $record): ?array
    {
        if (is_array($record['response'] ?? null)) {
            return $record['response'];
        }

        if (! is_string($record['response_encrypted'] ?? null)) {
            return null;
        }

        try {
            $response = json_decode(Crypt::decryptString($record['response_encrypted']), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new IdempotencyStoreUnavailableException;
        }

        if (! is_array($response)) {
            throw new IdempotencyStoreUnavailableException;
        }

        return $response;
    }

    /** @param array<string,mixed> $response */
    private function encryptResponse(array $response): string
    {
        try {
            return Crypt::encryptString(json_encode(
                $response,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (Throwable) {
            throw new IdempotencyStoreUnavailableException;
        }
    }

    /** @param array<string,mixed>|null $telemetry */
    private function generationTelemetry(?array $telemetry): ?array
    {
        if ($telemetry === null) {
            return null;
        }

        return [
            ...$telemetry,
            'idempotent_replayed' => false,
            'generation_telemetry_reference' => $this->telemetryReference($telemetry),
        ];
    }

    /** @param array<string,mixed>|null $generationTelemetry */
    private function replayTelemetry(?array $generationTelemetry): ?array
    {
        if ($generationTelemetry === null) {
            return null;
        }

        return [
            'provider' => 'idempotency_store',
            'model' => null,
            'response_id' => null,
            'input_tokens' => 0,
            'cached_input_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
            'estimated_cost' => 0.0,
            'provider_request_count' => 0,
            'semantic_attempt_count' => 0,
            'retry_count' => 0,
            'provider_duration_ms' => 0,
            'rate_card' => null,
            'idempotent_replayed' => true,
            'generation_telemetry_reference' => $this->telemetryReference($generationTelemetry),
        ];
    }

    /**
     * @param  array<string,mixed>  $telemetry
     * @return array{algorithm:string,digest:string}
     */
    private function telemetryReference(array $telemetry): array
    {
        return [
            'algorithm' => 'sha256',
            'digest' => hash('sha256', CanonicalJson::encode($telemetry)),
        ];
    }

    private function abandon(string $keyHash, string $requestHash): void
    {
        $lock = $this->cache->lock($this->lockKey($keyHash), (int) config('wireframe.idempotency.lock_seconds', 10));
        if (! $lock->get()) {
            return;
        }

        try {
            $record = $this->record($keyHash);
            if (($record['state'] ?? null) === 'processing'
                && hash_equals((string) ($record['request_hash'] ?? ''), $requestHash)) {
                $this->cache->forget($this->recordKey($keyHash));
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  callable():array<mixed>  $callback
     * @return array<mixed>
     */
    private function withLock(string $keyHash, callable $callback): array
    {
        $lock = $this->cache->lock($this->lockKey($keyHash), (int) config('wireframe.idempotency.lock_seconds', 10));
        if (! $lock->get()) {
            throw new IdempotencyInProgressException;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,mixed>|null */
    private function record(string $keyHash): ?array
    {
        $record = $this->cache->get($this->recordKey($keyHash));

        return is_array($record) ? $record : null;
    }

    /** @param array<string,mixed> $record */
    private function assertSameRequest(array $record, string $requestHash): void
    {
        if (! hash_equals((string) ($record['request_hash'] ?? ''), $requestHash)) {
            throw new IdempotencyConflictException;
        }
    }

    private function recordKey(string $keyHash): string
    {
        return "wireframe-idempotency:{$keyHash}";
    }

    private function lockKey(string $keyHash): string
    {
        return "wireframe-idempotency-lock:{$keyHash}";
    }
}
