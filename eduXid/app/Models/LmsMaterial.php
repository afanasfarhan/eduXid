<?php

namespace App\Models;

use App\Models\Concerns\SyncableLmsModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LmsMaterial extends Model
{
    use SyncableLmsModel;

    protected $fillable = [
        'sync_id',

        'topic_id',
        'atp_item_id',

        'title',
        'type',
        'content',
        'external_url',

        'file_path',
        'file_name',
        'mime_type',
        'file_size',

        'sort_order',
        'is_published',

        'sync_version',
        'sync_status',
        'last_synced_at',
    ];

    protected $casts = [
        'topic_id' => 'integer',
        'file_size' => 'integer',
        'sort_order' => 'integer',
        'is_published' => 'boolean',
        'sync_version' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (LmsMaterial $material): void {
            $path = $material->file_path;

            if (
                is_string($path)
                && $path !== ''
                && str_starts_with($path, 'lms/materials/')
                && Storage::disk('public')->exists($path)
            ) {
                Storage::disk('public')->delete($path);
            }
        });
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(LmsTopic::class, 'topic_id');
    }
}