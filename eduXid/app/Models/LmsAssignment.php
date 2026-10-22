<?php

namespace App\Models;

use App\Models\Concerns\SyncableLmsModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LmsAssignment extends Model
{
    use SyncableLmsModel;


    /*
    |--------------------------------------------------------------------------
    | FILLABLE
    |--------------------------------------------------------------------------
    */

    protected $fillable = [
        'sync_id',

        'topic_id',
        'atp_item_id',

        'title',
        'instructions',

        'available_from',
        'due_at',

        'submission_type',
        'max_score',

        'assignment_type',
        'exam_id',

        'is_published',

        'sync_version',
        'sync_status',
        'last_synced_at',
    ];


    /*
    |--------------------------------------------------------------------------
    | CASTS
    |--------------------------------------------------------------------------
    */

    protected $casts = [
        'topic_id' => 'integer',

        'exam_id' => 'integer',

        'available_from' => 'datetime',

        'due_at' => 'datetime',

        'max_score' => 'float',

        'is_published' => 'boolean',

        'sync_version' => 'integer',

        'last_synced_at' => 'datetime',
    ];


    /*
    |--------------------------------------------------------------------------
    | TOPIC
    |--------------------------------------------------------------------------
    */

    public function topic(): BelongsTo
    {
        return $this->belongsTo(
            LmsTopic::class,
            'topic_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SUBMISSIONS
    |--------------------------------------------------------------------------
    */

    public function submissions(): HasMany
    {
        return $this->hasMany(
            LmsAssignmentSubmission::class,
            'assignment_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | EXAM / CBT
    |--------------------------------------------------------------------------
    */

    public function exam(): BelongsTo
    {
        return $this->belongsTo(
            Exam::class,
            'exam_id'
        );
    }
}