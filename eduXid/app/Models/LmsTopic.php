<?php

namespace App\Models;

use App\Models\Concerns\SyncableLmsModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsTopic extends Model
{
    use SyncableLmsModel;
    protected $fillable = [
        'sync_id',
        'course_id',
        'title',
        'description',
        'sort_order',
        'is_published',
        'sync_version',
        'sync_status',
        'last_synced_at',
    ];

    protected $casts = [
        'course_id' => 'integer',
        'sort_order' => 'integer',
        'is_published' => 'boolean',
        'sync_version' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(LmsCourse::class, 'course_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(LmsMaterial::class, 'topic_id')->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LmsAssignment::class, 'topic_id')->orderBy('id');
    }
}