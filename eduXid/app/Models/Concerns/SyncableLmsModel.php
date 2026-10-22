<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait SyncableLmsModel
{
    protected static function syncOnlyFields(): array
    {
        return [
            'sync_version',
            'sync_status',
            'last_synced_at',
            'created_at',
            'updated_at',
        ];
    }

    protected static function bootSyncableLmsModel(): void
    {
        static::creating(function ($model): void {
            if (empty($model->sync_id)) {
                $model->sync_id = (string) Str::uuid();
            }

            $model->sync_version = max(1, (int) ($model->sync_version ?? 1));

            if (empty($model->sync_status)) {
                $model->sync_status = 'pending';
            }
        });

        static::updating(function ($model): void {
            if (static::isSyncing()) {
                return;
            }

            $dirtyFields = array_keys($model->getDirty());

            $contentChanged = collect($dirtyFields)
                ->contains(
                    fn (string $field): bool =>
                        !in_array($field, static::syncOnlyFields(), true)
                );

            if (!$contentChanged) {
                return;
            }

            /*
             * Jika caller secara eksplisit menaikkan sync_version
             * (pola controller eduXid: bump manual + konten),
             * hormati nilai tersebut — JANGAN bump lagi.
             */
            if ($model->isDirty('sync_version')) {
                $model->sync_version = max(1, (int) $model->sync_version);
                return;
            }

            /*
             * Konten berubah tanpa versi eksplisit (kode lama /
             * helper) — auto-bump satu tingkat sebagai fallback.
             */
            $currentVersion = max(
                1,
                (int) ($model->getOriginal('sync_version') ?? 1)
            );

            $model->sync_version = $currentVersion + 1;
            $model->sync_status = 'pending';
            $model->last_synced_at = null;
        });
    }

    protected static bool $syncing = false;

    public static function isSyncing(): bool
    {
        return static::$syncing;
    }

    public static function withoutSyncVersioning(callable $callback): mixed
    {
        $previousState = static::$syncing;
        static::$syncing = true;

        try {
            return $callback();
        } finally {
            static::$syncing = $previousState;
        }
    }

    public static function runAsSync(callable $callback): mixed
    {
        return static::withoutSyncVersioning($callback);
    }
}