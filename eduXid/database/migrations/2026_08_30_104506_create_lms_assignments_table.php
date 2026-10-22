<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_assignments', function (Blueprint $table) {
            $table->id();

            $table->uuid('sync_id')->unique();

            $table->foreignId('topic_id')
                ->constrained('lms_topics')
                ->cascadeOnDelete();

            $table->string('title');

            $table->longText('instructions')->nullable();

            $table->timestamp('available_from')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->enum('submission_type', [
                'text',
                'file',
                'photo',
                'text_file',
                'text_photo',
            ])->default('text');

            $table->decimal('max_score', 8, 2)
                ->default(100);

            /*
             * Jenis aktivitas:
             *
             * assignment = tugas biasa
             * cbt        = ujian/CBT
             */
            $table->string('assignment_type')
                ->default('assignment');

            /*
             * ID ujian CBT jika aktivitas
             * terhubung dengan sistem exam.
             */
            $table->unsignedBigInteger('exam_id')
                ->nullable();

            $table->boolean('is_published')
                ->default(false);

            $table->unsignedBigInteger('sync_version')
                ->default(1);

            $table->timestamp('last_synced_at')
                ->nullable();

            $table->timestamps();

            $table->index([
                'topic_id',
                'due_at',
            ]);

            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_assignments');
    }
};