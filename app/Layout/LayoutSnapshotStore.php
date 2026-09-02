<?php

namespace App\Layout;

use App\Exceptions\LayoutSnapshotStoreException;
use App\Support\CanonicalJson;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class LayoutSnapshotStore
{
    public function __construct(
        private readonly CacheManager $cache,
        private readonly LayoutSnapshotVerifier $verifier,
    ) {}

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array{snapshot:array<string,mixed>,storage:array<string,mixed>,created:bool}
     */
    public function put(array $snapshot): array
    {
        $snapshot = $this->verifier->verify($snapshot);
        $snapshotId = (string) $snapshot['snapshot_id'];
        $cacheStore = config('wireframe.idempotency.store');
        $lock = $this->cache
            ->store(is_string($cacheStore) && $cacheStore !== '' ? $cacheStore : null)
            ->lock('layout-snapshot-write:'.$snapshotId, $this->lockSeconds());

        try {
            return $lock->block($this->lockWaitSeconds(), function () use ($snapshot, $snapshotId): array {
                $existing = $this->read($snapshotId, includeExpired: true);
                if ($existing !== null && ! $this->expired($existing['storage'])) {
                    if (! hash_equals((string) $existing['snapshot']['snapshot_digest'], (string) $snapshot['snapshot_digest'])) {
                        throw new LayoutSnapshotStoreException('A different snapshot already exists at the content-addressed identifier.');
                    }

                    return [...$existing, 'created' => false];
                }
                if ($existing !== null) {
                    $this->disk()->delete($this->path($snapshotId));
                }

                $createdAt = now();
                $ttlSeconds = $this->ttlSeconds();
                $storage = [
                    'write_once' => true,
                    'encrypted' => true,
                    'created_at' => $createdAt->toIso8601String(),
                    'expires_at' => $createdAt->copy()->addSeconds($ttlSeconds)->toIso8601String(),
                    'ttl_seconds' => $ttlSeconds,
                ];
                $envelope = ['version' => 1, 'snapshot' => $snapshot, 'storage' => $storage];
                $encrypted = Crypt::encryptString(CanonicalJson::encode($envelope));
                $temporaryPath = $this->prefix().'/.tmp/'.$snapshotId.'-'.Str::uuid().'.enc';
                $path = $this->path($snapshotId);
                $disk = $this->disk();
                if (! $disk->put($temporaryPath, $encrypted, ['visibility' => 'private'])) {
                    throw new LayoutSnapshotStoreException('The encrypted Layout Snapshot could not be staged.');
                }
                try {
                    if ($disk->exists($path)) {
                        throw new LayoutSnapshotStoreException('The write-once Layout Snapshot path already exists.');
                    }
                    if (! $disk->move($temporaryPath, $path)) {
                        throw new LayoutSnapshotStoreException('The encrypted Layout Snapshot could not be committed.');
                    }
                } finally {
                    if ($disk->exists($temporaryPath)) {
                        $disk->delete($temporaryPath);
                    }
                }

                return ['snapshot' => $snapshot, 'storage' => $storage, 'created' => true];
            });
        } catch (LayoutSnapshotStoreException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new LayoutSnapshotStoreException('The Layout Snapshot store is unavailable.', previous: $exception);
        }
    }

    /** @return array{snapshot:array<string,mixed>,storage:array<string,mixed>}|null */
    public function find(string $snapshotId): ?array
    {
        if (preg_match('/\Als_[a-f0-9]{64}\z/D', $snapshotId) !== 1) {
            return null;
        }
        $record = $this->read($snapshotId);
        if ($record === null) {
            return null;
        }
        if ($this->expired($record['storage'])) {
            $this->disk()->delete($this->path($snapshotId));

            return null;
        }

        return $record;
    }

    public function pruneExpired(): int
    {
        $deleted = 0;
        foreach ($this->disk()->allFiles($this->prefix()) as $path) {
            if (! str_ends_with($path, '.enc') || str_contains($path, '/.tmp/')) {
                continue;
            }
            $snapshotId = Str::beforeLast(basename($path), '.enc');
            try {
                $record = $this->read($snapshotId, includeExpired: true);
                if ($record !== null && $this->expired($record['storage']) && $this->disk()->delete($path)) {
                    $deleted++;
                }
            } catch (LayoutSnapshotStoreException) {
                continue;
            }
        }

        return $deleted;
    }

    /** @return array{snapshot:array<string,mixed>,storage:array<string,mixed>}|null */
    private function read(string $snapshotId, bool $includeExpired = false): ?array
    {
        $disk = $this->disk();
        $path = $this->path($snapshotId);
        if (! $disk->exists($path)) {
            return null;
        }
        try {
            $encrypted = $disk->get($path);
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new LayoutSnapshotStoreException('The encrypted Layout Snapshot could not be restored.', previous: $exception);
        }
        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== 1
            || ! is_array($decoded['snapshot'] ?? null)
            || ! is_array($decoded['storage'] ?? null)) {
            throw new LayoutSnapshotStoreException('The Layout Snapshot store contains an invalid envelope.');
        }
        $snapshot = $this->verifier->verify($decoded['snapshot']);
        if (($snapshot['snapshot_id'] ?? null) !== $snapshotId) {
            throw new LayoutSnapshotStoreException('The stored Layout Snapshot identifier does not match its path.');
        }
        if (! $includeExpired && $this->expired($decoded['storage'])) {
            return ['snapshot' => $snapshot, 'storage' => $decoded['storage']];
        }

        return ['snapshot' => $snapshot, 'storage' => $decoded['storage']];
    }

    /** @param array<string,mixed> $storage */
    private function expired(array $storage): bool
    {
        try {
            return ! is_string($storage['expires_at'] ?? null) || Carbon::parse($storage['expires_at'])->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    private function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('wireframe.layout_snapshots.disk', 'local'));
    }

    private function prefix(): string
    {
        return trim((string) config('wireframe.layout_snapshots.prefix', 'layout-snapshots'), '/');
    }

    private function path(string $snapshotId): string
    {
        return $this->prefix().'/'.substr($snapshotId, 3, 2).'/'.$snapshotId.'.enc';
    }

    private function ttlSeconds(): int
    {
        return max(600, min(2_592_000, (int) config('wireframe.layout_snapshots.ttl_seconds', 604800)));
    }

    private function lockSeconds(): int
    {
        return max(5, min(60, (int) config('wireframe.layout_snapshots.lock_seconds', 15)));
    }

    private function lockWaitSeconds(): int
    {
        return max(1, min(30, (int) config('wireframe.layout_snapshots.lock_wait_seconds', 5)));
    }
}
