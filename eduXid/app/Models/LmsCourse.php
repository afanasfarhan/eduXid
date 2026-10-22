<?php

namespace App\Models;

use App\Models\Concerns\SyncableLmsModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsCourse extends Model
{
    use SyncableLmsModel;

    protected $fillable = [
        'sync_id',

        'teacher_id',
        'subject_id',
        'homeroom_id',

        'name',
        'description',

        'academic_year',
        'semester',

        'is_published',

        'sync_version',
        'sync_status',
        'last_synced_at',
    ];

    protected $casts = [
        'teacher_id'     => 'integer',
        'subject_id'     => 'integer',
        'homeroom_id'    => 'integer',

        'is_published'   => 'boolean',

        'sync_version'   => 'integer',

        'last_synced_at' => 'datetime',
    ];


    public function teacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'teacher_id'
        );
    }


    public function subject(): BelongsTo
    {
        return $this->belongsTo(
            Subject::class,
            'subject_id'
        );
    }


    public function homeroom(): BelongsTo
    {
        return $this->belongsTo(
            Homeroom::class,
            'homeroom_id'
        );
    }


    public function topics(): HasMany
    {
        return $this->hasMany(
            LmsTopic::class,
            'course_id'
        )->orderBy('sort_order');
    }
}