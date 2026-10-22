<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lms_materials', function (Blueprint $table) {
            $table->id();

            $table->uuid('sync_id')->unique();

            $table->foreignId('topic_id')
                ->constrained('lms_topics')
                ->cascadeOnDelete();

            $table->string('title');

            $table->enum('type', [
                'text',
                'pdf',
                'image',
                'video',
                'audio',
                'file',
                'link'
            ])->default('text');

            $table->longText('content')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->text('external_url')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_published')->default(false);

            $table->unsignedBigInteger('sync_version')->default(1);
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['topic_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_materials');
    }
};