<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hapus tabel lama jika ada (agar bersih dari setup Fase 1)
        Schema::dropIfExists('question_banks');

        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            
            // Relasi ke Guru dan Mata Pelajaran
            $table->foreignId('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            
            // Konten Soal
            $table->enum('jenis_soal', ['pilihan_ganda', 'benar_salah', 'jawaban_singkat', 'majemuk'])->default('pilihan_ganda');
            $table->longText('pertanyaan'); // LongText untuk menampung HTML, gambar base64, atau LaTeX
            $table->json('opsi_jawaban')->nullable(); // Format JSON: {"A":"Teks A", "B":"Teks B"}
            $table->text('kunci_jawaban')->nullable(); // Bisa berisi "A", ["A","C"], atau teks jawaban singkat
            
            // Metadata & Penilaian
            $table->decimal('bobot_nilai', 5, 2)->default(1.00); // Mendukung angka desimal seperti 2.5 atau 1.5
            $table->string('judul_materi')->nullable(); // Misal: "Persamaan Linear"
            $table->text('indikator')->nullable(); // Misal: "Siswa dapat menyelesaikan..."
            $table->enum('level_kognitif', ['C1', 'C2', 'C3', 'C4', 'C5', 'C6'])->default('C3');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_banks');
    }
};