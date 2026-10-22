<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('exams');
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->string('judul_ujian');
            $table->text('deskripsi')->nullable();
            $table->integer('durasi_menit')->default(90);
            $table->datetime('waktu_mulai')->nullable();
            $table->datetime('waktu_selesai')->nullable();
            $table->string('token')->unique();
            $table->enum('status', ['draft', 'aktif', 'selesai'])->default('draft');
            $table->boolean('acak_soal')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};