<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->string('cbt_lock_token_hash', 64)->nullable();
            $table->timestamp('cbt_lock_acquired_at')->nullable();
            $table->timestamp('cbt_last_seen_at')->nullable();
            $table->string('cbt_lock_ip', 45)->nullable();
            $table->string('cbt_lock_user_agent', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'cbt_lock_token_hash',
                'cbt_lock_acquired_at',
                'cbt_last_seen_at',
                'cbt_lock_ip',
                'cbt_lock_user_agent',
            ]);
        });
    }
};
