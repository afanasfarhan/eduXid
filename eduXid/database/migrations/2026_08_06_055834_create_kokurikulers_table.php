<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Daftar Kegiatan Kokurikuler
        Schema::create('kokurikulers', function (Blueprint $table) {
            $table->id();
            $table->string('tema');
            $table->string('nama_kegiatan');
            $table->foreignId('koordinator_id')->constrained('teachers')->cascadeOnDelete();
            $table->timestamps();
        });

        // 2. Tabel Penilaian Kokurikuler Siswa
        Schema::create('kokurikuler_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('kokurikuler_id')->constrained('kokurikulers')->cascadeOnDelete();
            $table->text('deskripsi')->nullable();
            $table->enum('predikat', ['A', 'B', 'C', 'D', 'E'])->default('B');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kokurikuler_grades');
        Schema::dropIfExists('kokurikulers');
    }
};