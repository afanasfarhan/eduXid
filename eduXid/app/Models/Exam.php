<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    use HasFactory;

    protected $table = 'exams';

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'judul_ujian',
        'deskripsi',
        'durasi_menit',
        'waktu_mulai',
        'waktu_selesai',
        'token',
        'status',
        'acak_soal',
    ];

    protected $casts = [
        'waktu_mulai' => 'datetime',
        'waktu_selesai' => 'datetime',
        'acak_soal' => 'boolean',
    ];

    // Relasi pivot ke QuestionBank
    public function questions()
    {
        return $this->belongsToMany(
            QuestionBank::class,
            'exam_questions',
            'exam_id',
            'question_bank_id'
        )->withPivot('urutan')
         ->orderBy('exam_questions.urutan');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function sessions()
    {
        return $this->hasMany(ExamSession::class);
    }
}