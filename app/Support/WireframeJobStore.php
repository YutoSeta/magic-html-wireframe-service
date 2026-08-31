<?php

namespace App\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

final class WireframeJobStore
{
    private Repository $cache;

    public function __construct(CacheManager $cache)
    {
        $store = config('wireframe.idempotency.store');
        $this->cache = $cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    /** @param array<string,mixed> $payload @return array{job:array<string,mixed>,created:bool,conflict:bool} */
    public function claim(string $idempotencyKey, array $payload): array
    {
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', CanonicalJson::encode($payload));

        return $this->cache->lock("wireframe-job-claim:{$keyHash}", 10)->block(5, function () use ($keyHash, $requestHash, $payload): array {
            $pointerKey = "wireframe-job-idempotency:{$keyHash}";
            $pointer = $this->cache->get($pointerKey);
            if (is_array($pointer) && ($job = $this->find((string) ($pointer['job_id'] ?? ''))) !== null) {
                return [
                    'job' => $job,
                    'created' => false,
                    'conflict' => ! hash_equals((string) ($pointer['request_hash'] ?? ''), $requestHash),
                ];
            }

            $id = (string) str()->uuid();
            $now = now()->toIso8601String();
            $record = [
                'contract_version' => '1.0',
                'id' => $id,
                'status' => 'queued',
                'progress' => 0.0,
                'semantic_attempt' => 1,
                'attempt_telemetry' => [],
                'provider_completion_claimed' => false,
                'created_at' => $now,
                'updated_at' => $now,
                'context_encrypted' => $this->encrypt($payload),
            ];
            $this->write($record);
            $this->cache->put($pointerKey, ['job_id' => $id, 'request_hash' => $requestHash], $this->expiresAt());

            return ['job' => $this->publicRecord($record), 'created' => true, 'conflict' => false];
        });
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $record = $this->internal($id);

        return $record === null ? null : $this->publicRecord($record);
    }

    /** @return array{payload:array<string,mixed>,provider_response_id:string,semantic_attempt:int}|null */
    public function providerContext(string $id): ?array
    {
        $record = $this->internal($id);
        if ($record === null
            || ! is_string($record['context_encrypted'] ?? null)
            || ! is_string($record['provider_response_id'] ?? null)) {
            return null;
        }
        $payload = $this->decrypt($record['context_encrypted']);

        return [
            'payload' => $payload,
            'provider_response_id' => $record['provider_response_id'],
            'semantic_attempt' => max(1, (int) ($record['semantic_attempt'] ?? 1)),
        ];
    }

    /** @return array<string,mixed> */
    public function started(string $id, string $providerResponseId, string $providerStatus): array
    {
        return $this->update($id, [
            'status' => $providerStatus === 'completed' ? 'in_progress' : $providerStatus,
            'progress' => $providerStatus === 'queued' ? 0.05 : 0.1,
            'provider_response_id' => $providerResponseId,
            'provider_completion_claimed' => false,
        ]);
    }

    /** @return array<string,mixed> */
    public function pending(string $id, string $providerStatus): array
    {
        return $this->update($id, [
            'status' => $providerStatus,
            'progress' => $providerStatus === 'queued' ? 0.05 : 0.5,
        ]);
    }

    public function claimSemanticRetry(string $id, string $providerResponseId, int $semanticAttempt): bool
    {
        return $this->cache->lock("wireframe-job-update:{$id}", 10)->block(5, function () use ($id, $providerResponseId, $semanticAttempt): bool {
            $record = $this->internal($id);
            if ($record === null
                || (string) ($record['provider_response_id'] ?? '') !== $providerResponseId
                || (int) ($record['semantic_attempt'] ?? 1) !== $semanticAttempt
                || ($record['provider_completion_claimed'] ?? null) !== true) {
                return false;
            }
            $record = array_replace($record, [
                'status' => 'in_progress',
                'progress' => 0.5,
                'semantic_attempt' => $semanticAttempt + 1,
                'provider_response_id' => null,
                'provider_completion_claimed' => false,
                'updated_at' => now()->toIso8601String(),
            ]);
            $this->write($record);

            return true;
        });
    }

    public function claimProviderCompletion(string $id, string $providerResponseId, int $semanticAttempt): bool
    {
        return $this->cache->lock("wireframe-job-update:{$id}", 10)->block(5, function () use ($id, $providerResponseId, $semanticAttempt): bool {
            $record = $this->internal($id);
            if ($record === null
                || (string) ($record['provider_response_id'] ?? '') !== $providerResponseId
                || (int) ($record['semantic_attempt'] ?? 1) !== $semanticAttempt
                || ($record['provider_completion_claimed'] ?? false) === true) {
                return false;
            }
            $record['provider_completion_claimed'] = true;
            $record['updated_at'] = now()->toIso8601String();
            $this->write($record);

            return true;
        });
    }

    /** @param array<string,mixed> $telemetry */
    public function recordAttemptTelemetry(string $id, array $telemetry): void
    {
        $this->cache->lock("wireframe-job-update:{$id}", 10)->block(5, function () use ($id, $telemetry): void {
            $record = $this->internal($id);
            if ($record === null) {
                throw new RuntimeException('The wireframe job does not exist or has expired.');
            }
            $attempts = is_array($record['attempt_telemetry'] ?? null) ? $record['attempt_telemetry'] : [];
            $attempts[] = $telemetry;
            $record['attempt_telemetry'] = $attempts;
            $record['updated_at'] = now()->toIso8601String();
            $this->write($record);
        });
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function succeeded(string $id, array $result): array
    {
        $record = $this->internal($id);
        if ($record === null) {
            throw new RuntimeException('The wireframe job does not exist or has expired.');
        }
        $attempts = is_array($record['attempt_telemetry'] ?? null) ? $record['attempt_telemetry'] : [];
        if (is_array($result['telemetry'] ?? null)) {
            $attempts[] = $result['telemetry'];
            $result['telemetry'] = $this->aggregateTelemetry($attempts);
        }

        return $this->update($id, [
            'status' => 'succeeded',
            'progress' => 1.0,
            'result_encrypted' => $this->encrypt($result),
            'failure' => null,
        ]);
    }

    /** @return array<string,mixed> */
    public function failed(string $id, string $type, string $detail): array
    {
        $record = $this->internal($id);
        $attempts = is_array($record['attempt_telemetry'] ?? null) ? $record['attempt_telemetry'] : [];

        return $this->update($id, [
            'status' => 'failed',
            'progress' => 1.0,
            'failure' => ['type' => $type, 'detail' => $detail],
            'telemetry' => $attempts === [] ? null : $this->aggregateTelemetry($attempts),
        ]);
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private function update(string $id, array $changes): array
    {
        return $this->cache->lock("wireframe-job-update:{$id}", 10)->block(5, function () use ($id, $changes): array {
            $record = $this->internal($id);
            if ($record === null) {
                throw new RuntimeException('The wireframe job does not exist or has expired.');
            }
            $record = array_replace($record, $changes, ['updated_at' => now()->toIso8601String()]);
            $this->write($record);

            return $this->publicRecord($record);
        });
    }

    /** @return array<string,mixed>|null */
    private function internal(string $id): ?array
    {
        if (! preg_match('/\A[0-9a-f-]{36}\z/iD', $id)) {
            return null;
        }
        $record = $this->cache->get("wireframe-job:{$id}");

        return is_array($record) ? $record : null;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function publicRecord(array $record): array
    {
        $public = array_diff_key($record, array_flip(['attempt_telemetry', 'context_encrypted', 'provider_completion_claimed', 'provider_response_id', 'result_encrypted']));
        if (is_string($record['result_encrypted'] ?? null)) {
            $public['result'] = $this->decrypt($record['result_encrypted']);
        }

        return $public;
    }

    /** @param array<string,mixed> $record */
    private function write(array $record): void
    {
        if (! $this->cache->put("wireframe-job:{$record['id']}", $record, $this->expiresAt())) {
            throw new RuntimeException('The wireframe job store is unavailable.');
        }
    }

    /** @param array<string,mixed> $value */
    private function encrypt(array $value): string
    {
        try {
            return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            throw new RuntimeException('The wireframe job store could not protect its payload.');
        }
    }

    /** @return array<string,mixed> */
    private function decrypt(string $value): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('The wireframe job store could not restore its payload.');
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('The wireframe job store contains an invalid payload.');
        }

        return $decoded;
    }

    private function expiresAt(): Carbon
    {
        return now()->addSeconds(max(600, (int) config('wireframe.jobs.ttl_seconds', 86400)));
    }

    /** @param list<array<string,mixed>> $attempts @return array<string,mixed> */
    private function aggregateTelemetry(array $attempts): array
    {
        $last = $attempts[array_key_last($attempts)] ?? [];
        $aggregate = $last;
        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'provider_request_count', 'semantic_attempt_count', 'retry_count', 'provider_duration_ms'] as $field) {
            $values = array_column($attempts, $field);
            $aggregate[$field] = count($values) === count($attempts)
                && collect($values)->every(static fn (mixed $value): bool => is_int($value))
                    ? array_sum($values)
                    : null;
        }
        $costs = array_column($attempts, 'estimated_cost');
        $aggregate['estimated_cost'] = count($costs) === count($attempts)
            && collect($costs)->every(static fn (mixed $value): bool => is_int($value) || is_float($value))
                ? array_sum($costs)
                : null;

        return $aggregate;
    }
}
