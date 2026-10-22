<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingAssignment extends Model
{
    protected $table = 'teaching_assignments';

    protected $guarded = [];

    /**
     * Guru yang mengajar.
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'teacher_id',
            'id'
        );
    }

    /**
     * Rombel tempat pembelajaran berlangsung.
     */
    public function homeroom(): BelongsTo
    {
        return $this->belongsTo(
            Homeroom::class,
            'homeroom_id',
            'id'
        );
    }

    /**
     * Mata pelajaran.
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(
            Subject::class,
            'subject_id',
            'id'
        );
    }
}
