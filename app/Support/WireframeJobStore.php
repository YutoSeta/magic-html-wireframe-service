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

    /** @return array{payload:array<string,mixed>,provider_response_id:string}|null */
    public function providerContext(string $id): ?array
    {
        $record = $this->internal($id);
        if ($record === null
            || ! is_string($record['context_encrypted'] ?? null)
            || ! is_string($record['provider_response_id'] ?? null)) {
            return null;
        }
        $payload = $this->decrypt($record['context_encrypted']);

        return ['payload' => $payload, 'provider_response_id' => $record['provider_response_id']];
    }

    /** @return array<string,mixed> */
    public function started(string $id, string $providerResponseId, string $providerStatus): array
    {
        return $this->update($id, [
            'status' => $providerStatus === 'completed' ? 'in_progress' : $providerStatus,
            'progress' => $providerStatus === 'queued' ? 0.05 : 0.1,
            'provider_response_id' => $providerResponseId,
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

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function succeeded(string $id, array $result): array
    {
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
        return $this->update($id, [
            'status' => 'failed',
            'progress' => 1.0,
            'failure' => ['type' => $type, 'detail' => $detail],
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
        $public = array_diff_key($record, array_flip(['context_encrypted', 'provider_response_id', 'result_encrypted']));
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
}
