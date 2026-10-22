<?php

namespace App\Models\Concerns;

use App\Services\Sync\LmsSyncService;

trait LmsSyncable
{
    protected static function bootLmsSyncable(): void
    {
        static::creating(function ($model) {

            if (
                $model->sync_version === null
            ) {
                $model->sync_version = 1;
            }
        });

        static::updating(function ($model) {

            $model->sync_version =
                ((int) (
                    $model->getOriginal(
                        'sync_version'
                    ) ?? 0
                )) + 1;
        });

        static::created(function ($model) {

            app(LmsSyncService::class)
                ->push(
                    $model,
                    'create'
                );
        });

        static::updated(function ($model) {

            app(LmsSyncService::class)
                ->push(
                    $model,
                    'update'
                );
        });

        static::deleted(function ($model) {

            app(LmsSyncService::class)
                ->push(
                    $model,
                    'delete'
                );
        });
    }
}