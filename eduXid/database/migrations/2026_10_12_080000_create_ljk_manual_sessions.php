<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('ljk_manual_sessions')) {
            Schema::create('ljk_manual_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 80)->unique();
                $table->unsignedBigInteger('teacher_id')->index();
                $table->unsignedBigInteger('teaching_assignment_id')->index();
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('homeroom_id');
                $table->string('title', 150);
                $table->date('tanggal');
                $table->string('jenis_penilaian', 20)->default('sumatif');
                $table->unsignedInteger('question_count');
                $table->longText('keys_json');
                $table->string('key_fingerprint', 64);
                $table->unsignedBigInteger('version')->default(1);
                $table->timestamps();
            });
        }
    }
    public function down(): void { Schema::dropIfExists('ljk_manual_sessions'); }
};
