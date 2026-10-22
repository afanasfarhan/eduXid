<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LmsAssignmentSubmission extends Model
{
    protected $fillable = [
        'sync_id',

        'assignment_id',
        'student_id',

        'answer_text',

        'file_path',
        'file_name',
        'mime_type',
        'file_size',

        'submitted_at',

        'score',
        'teacher_feedback',
        'status',

        'sync_version',
        'sync_status',
        'last_synced_at',
    ];

    protected $casts = [
        'assignment_id' => 'integer',
        'student_id' => 'string',
        'file_size' => 'integer',
        'submitted_at' => 'datetime',
        'score' => 'float',
        'sync_version' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    protected static bool $syncing = false;

    protected static function booted(): void
    {
        static::creating(function (LmsAssignmentSubmission $submission): void {
            if (empty($submission->sync_id)) {
                $submission->sync_id = (string) Str::uuid();
            }

            $submission->sync_version = max(1, (int) ($submission->sync_version ?? 1));

            if (empty($submission->sync_status)) {
                $submission->sync_status = 'pending';
            }
        });

        static::updating(function (LmsAssignmentSubmission $submission): void {
            if (static::$syncing) {
                return;
            }

            $dirtyFields = array_keys($submission->getDirty());

            $syncOnlyFields = ['sync_version', 'sync_status', 'last_synced_at'];

            $contentChanged = collect($dirtyFields)
                ->contains(
                    fn (string $field): bool => !in_array($field, $syncOnlyFields, true)
                );

            if (!$contentChanged) {
                return;
            }

            /*
             * Versi eksplisit dari caller (controller grade/review)
             * dihormati — tidak ditimpa bump otomatis.
             */
            if ($submission->isDirty('sync_version')) {
                $submission->sync_version = max(1, (int) $submission->sync_version);
                return;
            }

            /*
             * Konten berubah tanpa versi eksplisit —
             * auto-bump satu tingkat sebagai fallback.
             */
            $currentVersion = max(
                1,
                (int) ($submission->getOriginal('sync_version') ?? 1)
            );

            $submission->sync_version = $currentVersion + 1;
            $submission->sync_status = 'pending';
            $submission->last_synced_at = null;
        });
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

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(LmsAssignment::class, 'assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id', 'login_id');
    }
}