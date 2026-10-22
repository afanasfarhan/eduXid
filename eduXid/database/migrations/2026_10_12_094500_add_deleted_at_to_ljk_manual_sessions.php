<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ljk_manual_sessions') && !Schema::hasColumn('ljk_manual_sessions', 'deleted_at')) {
            Schema::table('ljk_manual_sessions', function (Blueprint $table) {
                $table->timestamp('deleted_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ljk_manual_sessions') && Schema::hasColumn('ljk_manual_sessions', 'deleted_at')) {
            Schema::table('ljk_manual_sessions', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};
