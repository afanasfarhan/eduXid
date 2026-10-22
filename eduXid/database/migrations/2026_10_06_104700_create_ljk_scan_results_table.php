<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ljk_scan_results')) {
            return;
        }

        Schema::create('ljk_scan_results', function (Blueprint $table) {
            $table->id();
            $table->uuid('scan_id')->unique();
            $table->unsignedBigInteger('exam_id')->nullable()->index();
            $table->string('exam_sync_id')->nullable()->index();
            $table->unsignedBigInteger('teaching_assignment_id')->nullable()->index();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->string('nisn', 20)->nullable()->index();
            $table->string('student_name')->nullable();
            $table->string('template_version', 40)->default('LJK-A4-V2');
            $table->string('source_type', 30)->default('image');
            $table->string('source_name')->nullable();
            $table->unsignedInteger('source_index')->nullable();
            $table->string('image_path')->nullable();
            $table->longText('answers_json')->nullable();
            $table->longText('answer_confidence_json')->nullable();
            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->decimal('score', 7, 2)->default(0);
            $table->decimal('confidence', 6, 4)->nullable();
            $table->string('status', 30)->default('review')->index();
            $table->string('issue_code', 80)->nullable();
            $table->text('issue_message')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('device_id')->nullable();
            $table->string('source_device_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['exam_id', 'student_id', 'is_active'], 'ljk_exam_student_active_idx');
            $table->index(['exam_sync_id', 'nisn'], 'ljk_exam_sync_nisn_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ljk_scan_results');
    }
};
