<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentAnswer extends Model
{
    use HasFactory;

    protected $table = 'student_answers';

    protected $fillable = [
        'exam_session_id',
        'question_bank_id',
        'jawaban_siswa',
        'is_benar',
        'nilai_per_soal',
    ];

    // Cast is_benar ke boolean
    protected $casts = [
        'is_benar' => 'boolean',
    ];

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function question()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }
}