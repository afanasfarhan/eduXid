<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionBank extends Model
{
    protected $table = 'question_banks';

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'atp_item_id',
        'tingkat_kelas',
        'jenis_soal',
        'pertanyaan',
        'opsi_jawaban',
        'kunci_jawaban',
        'bobot_nilai',
        'judul_materi',
        'indikator',
        'level_kognitif',
        'gambar_soal',
        'video_url',
        'tka_group_code',
        'tka_stimulus',
        'tka_category',
        'tka_statement_order',
    ];

    protected $casts = [
        'opsi_jawaban' => 'array',
        'tingkat_kelas' => 'integer',
        'tka_category' => 'boolean',
        'tka_statement_order' => 'integer',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
