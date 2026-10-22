<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Bank Soal
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->enum('jenis_soal', ['Pilihan Ganda', 'Esai', 'Benar-Salah']);
            $table->text('pertanyaan');
            $table->string('pilihan_a')->nullable();
            $table->string('pilihan_b')->nullable();
            $table->string('pilihan_c')->nullable();
            $table->string('pilihan_d')->nullable();
            $table->string('pilihan_e')->nullable();
            $table->string('kunci_jawaban')->nullable(); // Diisi A/B/C/D/E atau teks esai singkat
            $table->integer('bobot_nilai')->default(1); // Bobot poin soal ini
            $table->timestamps();
        });

        // 2. Tabel Ujian / Cetak Soal
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->foreignId('homeroom_id')->constrained('homerooms')->onDelete('cascade'); // Untuk kelas mana ujian ini
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->string('judul_ujian');
            $table->text('keterangan')->nullable();
            $table->integer('durasi_menit')->default(90);
            $table->boolean('is_published')->default(false); // Status apakah soal sudah final untuk dicetak/dikerjakan
            $table->timestamps();
        });

        // Tabel relasi many-to-many antara Ujian dan Bank Soal
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->onDelete('cascade');
            $table->foreignId('question_bank_id')->constrained('question_banks')->onDelete('cascade');
            $table->integer('urutan')->default(1);
            $table->timestamps();
        });

        // 3. Tabel Hasil Koreksi LJK / Jawaban Siswa
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('question_bank_id')->constrained('question_banks')->onDelete('cascade');
            $table->string('jawaban_siswa')->nullable(); // Pilihan A/B/C/D/E
            $table->boolean('is_benar')->default(false);
            $table->integer('nilai_per_soal')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('question_banks');
    }
};