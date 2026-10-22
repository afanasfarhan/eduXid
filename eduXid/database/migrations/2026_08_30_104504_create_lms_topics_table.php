<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_topics', function (Blueprint $table) {
            $table->id();

            $table->uuid('sync_id')->unique();

            $table->foreignId('course_id')
                ->constrained('lms_courses')
                ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_published')->default(false);

            $table->unsignedBigInteger('sync_version')->default(1);
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['course_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_topics');
    }
};