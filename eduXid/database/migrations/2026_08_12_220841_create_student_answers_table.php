<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
	Schema::dropIfExists('student_answers');
        Schema::create('student_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->onDelete('cascade');
            $table->foreignId('question_bank_id')->constrained('question_banks')->onDelete('cascade');
            $table->text('jawaban_siswa')->nullable();
            $table->boolean('is_benar')->default(false);
            $table->decimal('nilai_per_soal', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answers');
    }
};