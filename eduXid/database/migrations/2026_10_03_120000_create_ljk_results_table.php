<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ljk_results')) {
            return;
        }

        Schema::create('ljk_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('exam_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('teaching_assignment_id');
            $table->unsignedBigInteger('homeroom_id')->nullable();
            $table->string('nisn', 32);
            $table->date('exam_date');
            $table->string('source', 20)->default('scanner');
            $table->unsignedSmallInteger('question_count');
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->unsignedSmallInteger('wrong_count')->default(0);
            $table->decimal('score', 6, 2)->default(0);
            $table->longText('answers_json');
            $table->longText('confidence_json')->nullable();
            $table->unsignedSmallInteger('ambiguous_count')->default(0);
            $table->string('template_version', 32)->default('LJK-A4-V2');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id'], 'ljk_results_exam_student_unique');
            $table->index(['teacher_id', 'exam_date']);
            $table->index(['teaching_assignment_id', 'exam_date']);
            $table->index('nisn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ljk_results');
    }
};
