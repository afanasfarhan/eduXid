<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('arena_grade_commits')) return;
        Schema::create('arena_grade_commits', function (Blueprint $table) {
            $table->id();
            $table->string('request_sync_id', 100)->unique();
            $table->string('package_id', 90);
            $table->foreignId('student_id');
            $table->foreignId('teaching_assignment_id');
            $table->date('grade_date');
            $table->string('game_type', 30);
            $table->unsignedTinyInteger('score');
            $table->string('source_sha256', 64);
            $table->boolean('receipt_confirmed')->default(false);
            $table->timestamps();
            $table->unique(['package_id', 'student_id'], 'arena_grade_one_commit_per_student');
            $table->index(['teaching_assignment_id', 'grade_date'], 'arena_grade_ta_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arena_grade_commits');
    }
};
