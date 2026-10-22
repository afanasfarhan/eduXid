<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cloud_ljk_pair_keys_v21')) {
            Schema::create('cloud_ljk_pair_keys_v21', function (Blueprint $table) {
                $table->id();
                $table->string('school_id', 8);
                $table->unsignedBigInteger('teacher_id');
                $table->string('source_hp', 64);
                $table->string('target_pc', 64);
                $table->text('encrypted_key');
                $table->char('claim_hash', 64)->nullable();
                $table->unsignedBigInteger('claim_expires_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->unique(['school_id','source_hp','target_pc'], 'ljk_v21_pair_unique');
            });
        }
        if (!Schema::hasTable('cloud_ljk_nonces_v21')) {
            Schema::create('cloud_ljk_nonces_v21', function (Blueprint $table) {
                $table->id();
                $table->char('nonce_hash', 64)->unique();
                $table->string('school_id', 8);
                $table->unsignedBigInteger('teacher_id');
                $table->string('source_hp', 64);
                $table->string('target_pc', 64);
                $table->string('session_ref', 200);
                $table->string('scan_id', 100);
                $table->unsignedBigInteger('issued_at');
                $table->unsignedBigInteger('expires_at');
                $table->string('status', 12)->default('pending');
                $table->char('receipt_hash', 64)->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
                $table->index(['school_id','teacher_id','scan_id'], 'ljk_v21_scan_lookup');
            });
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('cloud_ljk_nonces_v21');
        Schema::dropIfExists('cloud_ljk_pair_keys_v21');
    }
};
