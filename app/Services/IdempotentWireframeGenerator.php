<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdempotencyInProgressException;
use App\Exceptions\IdempotencyStoreUnavailableException;
use App\Services\Contracts\WireframeGenerator;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
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
     * @return array{wireframe:array<string,mixed>,replayed:bool}
     */
    public function generate(
        string $idempotencyKey,
        string $requestBytes,
        string $contractVersion,
        array $siteAst,
        array $brief,
        string $locale,
    ): array {
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', "wireframes\0{$contractVersion}\0{$requestBytes}");
        $claim = $this->claim($keyHash, $requestHash);

        if ($claim['wireframe'] !== null) {
            return ['wireframe' => $claim['wireframe'], 'replayed' => true];
        }

        try {
            $wireframe = $this->generator->generate($siteAst, $brief, $locale);
        } catch (Throwable $exception) {
            $this->abandon($keyHash, $requestHash);

            throw $exception;
        }

        return $this->complete($keyHash, $requestHash, $wireframe);
    }

    /** @return array{wireframe:array<string,mixed>|null} */
    private function claim(string $keyHash, string $requestHash): array
    {
        return $this->withLock($keyHash, function () use ($keyHash, $requestHash): array {
            $record = $this->record($keyHash);
            if ($record !== null) {
                $this->assertSameRequest($record, $requestHash);
                if (($record['state'] ?? null) === 'completed' && is_array($record['response'] ?? null)) {
                    return ['wireframe' => $record['response']];
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

            return ['wireframe' => null];
        });
    }

    /**
     * @param  array<string,mixed>  $wireframe
     * @return array{wireframe:array<string,mixed>,replayed:bool}
     */
    private function complete(string $keyHash, string $requestHash, array $wireframe): array
    {
        return $this->withLock($keyHash, function () use ($keyHash, $requestHash, $wireframe): array {
            $record = $this->record($keyHash);
            if ($record === null) {
                throw new IdempotencyInProgressException('The Idempotency-Key claim expired before its response could be recorded.');
            }

            $this->assertSameRequest($record, $requestHash);
            if (($record['state'] ?? null) === 'completed' && is_array($record['response'] ?? null)) {
                return ['wireframe' => $record['response'], 'replayed' => true];
            }
            if (($record['state'] ?? null) !== 'processing') {
                throw new IdempotencyInProgressException;
            }

            $stored = $this->cache->forever($this->recordKey($keyHash), [
                'version' => 1,
                'operation' => 'wireframes.generate',
                'request_hash' => $requestHash,
                'state' => 'completed',
                'response' => $wireframe,
            ]);
            if (! $stored) {
                throw new IdempotencyStoreUnavailableException;
            }

            return ['wireframe' => $wireframe, 'replayed' => false];
        });
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
