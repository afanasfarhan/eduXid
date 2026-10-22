<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamSession extends Model
{
    use HasFactory;

    protected $table = 'exam_sessions';

    protected $fillable = [
        'exam_id',
        'student_id',
        'status',
        'waktu_masuk',
        'waktu_submit',
        'nilai_akhir',
        'sync_id',
        'feedback',
        'cbt_lock_token_hash',
        'cbt_lock_acquired_at',
        'cbt_last_seen_at',
        'cbt_lock_ip',
        'cbt_lock_user_agent',
    ];

    protected $casts = [
        'waktu_masuk' => 'datetime',
        'waktu_submit' => 'datetime',
        'cbt_lock_acquired_at' => 'datetime',
        'cbt_last_seen_at' => 'datetime',
    ];

public function exam()
{
    return $this->belongsTo(Exam::class);
}

public function student()
{
    return $this->belongsTo(Student::class);
}

    public function answers()
    {
        return $this->hasMany(StudentAnswer::class);
    }
}