<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $table = 'subjects';

    protected $guarded = [];

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(
            TeachingAssignment::class,
            'subject_id',
            'id'
        );
    }
}