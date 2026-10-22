<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cloud_ljk_publications_v21r14')) return;
        Schema::create('cloud_ljk_publications_v21r14', function (Blueprint $table): void {
            $table->id();
            $table->string('school_id', 8);
            $table->unsignedBigInteger('teacher_id');
            $table->string('session_ref', 200);
            $table->unsignedBigInteger('student_id'); // Local-only DB; never sent to Cloud.
            $table->string('scan_id', 100);
            $table->char('nonce_hash', 64);
            $table->char('answers_sha256', 64);
            $table->char('quality_tag_hash', 64);
            $table->char('key_fingerprint', 64);
            $table->unsignedBigInteger('teaching_assignment_id');
            $table->date('tanggal');
            $table->string('score_type', 20);
            $table->decimal('score', 7, 2);
            $table->unsignedInteger('correct_count');
            $table->string('status', 16)->default('published');
            $table->timestamps();
            // Protection even when one student is scanned twice under TWO distinct nonces.
            $table->unique(['school_id','teacher_id','session_ref','student_id'], 'ljk_v21r14_one_student_one_session');
            $table->unique(['school_id','teacher_id','session_ref','scan_id'], 'ljk_v21r14_one_scan_one_session');
            $table->unique('nonce_hash', 'ljk_v21r14_one_nonce');
            $table->unique(['teacher_id','student_id','teaching_assignment_id','tanggal','score_type'], 'ljk_v21r14_one_daily_slot');
        });
    }

    public function down(): void { Schema::dropIfExists('cloud_ljk_publications_v21r14'); }
};
