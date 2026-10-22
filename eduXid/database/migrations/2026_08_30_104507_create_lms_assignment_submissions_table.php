<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_assignment_submissions', function (Blueprint $table) {
            $table->id();

            $table->uuid('sync_id')->unique();

            $table->foreignId('assignment_id')
                ->constrained('lms_assignments')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->longText('answer_text')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->timestamp('submitted_at')->nullable();

            $table->decimal('score', 8, 2)->nullable();

            $table->text('teacher_feedback')->nullable();

            $table->enum('status', [
                'draft',
                'submitted',
                'graded',
                'returned'
            ])->default('draft');

            /*
             * Status sinkronisasi P2P
             */
            $table->enum('sync_status', [
                'local',
                'pending',
                'synced',
                'failed'
            ])->default('local');

            $table->unsignedBigInteger('sync_version')->default(1);

            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['assignment_id', 'student_id']);

            $table->index(['student_id', 'sync_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_assignment_submissions');
    }
};