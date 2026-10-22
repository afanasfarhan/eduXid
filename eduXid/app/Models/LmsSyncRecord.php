<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LmsSyncRecord extends Model
{
    protected $table = 'lms_sync_records';

    protected $fillable = [
        'sync_id',

        'node_id',
        'node_type',

        'target_device_id',
        'target_homeroom_id',

        'entity_type',
        'entity_id',

        'operation',
        'version',

        'payload',

        'status',
        'last_error',

'attempts',
'last_attempt_at',

        'synced_at',
    ];

    protected $casts = [
        'version' => 'integer',

        'target_homeroom_id' => 'integer',

        'payload' => 'array',

        'synced_at' => 'datetime',

        'created_at' => 'datetime',
        'updated_at' => 'datetime',

        'attempts'        => 'integer',
        'last_attempt_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LmsSyncRecord $record): void {
            if (empty($record->sync_id)) {
                $record->sync_id = (string) Str::uuid();
            }

            $record->version = max(1, (int) ($record->version ?? 1));

            if (empty($record->status)) {
                $record->status = 'pending';
            }
        });
    }

    public function markPending(?string $message = null): bool
    {
        return $this->forceFill([
            'status' => 'pending',
            'error_message' => $message,
            'synced_at' => null,
        ])->saveQuietly();
    }

    public function markProcessing(): bool
    {
        return $this->forceFill([
            'status' => 'processing',
            'error_message' => null,
        ])->saveQuietly();
    }

    public function markSynced(): bool
    {
        return $this->forceFill([
            'status' => 'synced',
            'error_message' => null,
            'synced_at' => now(),
        ])->saveQuietly();
    }

    public function markFailed(?string $message = null): bool
    {
        return $this->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'synced_at' => null,
        ])->saveQuietly();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isSynced(): bool
    {
        return $this->status === 'synced';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCreate(): bool
    {
        return $this->operation === 'create';
    }

    public function isUpdate(): bool
    {
        return $this->operation === 'update';
    }

    public function isDelete(): bool
    {
        return $this->operation === 'delete';
    }

    public function needsSync(): bool
    {
        return in_array($this->status, ['pending', 'failed', 'processing'], true);
    }

    public function isFinished(): bool
    {
        return $this->status === 'synced';
    }

    public function hasValidOperation(): bool
    {
        return in_array($this->operation, ['create', 'update', 'delete'], true);
    }

    public function hasEntityIdentity(): bool
    {
        return !empty($this->entity_type) && !empty($this->entity_id);
    }
}