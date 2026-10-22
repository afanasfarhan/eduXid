<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * [RESTORE-SAFE] Guard hasTable - restore dari backup lama
     * dapat mengembalikan tabel migrations ke kondisi sebelum
     * migrasi ini; tanpa guard, re-run gagal "table already exists"
     * padahal struktur sudah ada.
     */
    public function up(): void
    {
        if (Schema::hasTable('sync_dead_letters')) {
            return;
        }

        Schema::create('sync_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->index();
            $table->string('device_id')->index();
            $table->string('entity_type')->index();
            $table->string('entity_id')->nullable();
            $table->string('operation')->nullable();
            $table->unsignedBigInteger('version')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status')->default('retrying'); // retrying | dead | resolved
            $table->text('last_reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'device_id']);
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};