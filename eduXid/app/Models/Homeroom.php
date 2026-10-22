<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Homeroom extends Model
{
    protected $table = 'homerooms';

    protected $guarded = [];

    /**
     * Guru wali kelas.
     */
    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(
            Teacher::class,
            'homeroom_teacher_id',
            'id'
        );
    }

    /**
     * Semua pembelajaran dalam rombel.
     */
    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(
            TeachingAssignment::class,
            'homeroom_id',
            'id'
        );
    }

    /**
     * Siswa yang terdaftar dalam rombel.
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(
            Enrollment::class,
            'homeroom_id',
            'id'
        );
    }
}