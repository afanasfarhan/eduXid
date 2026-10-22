<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_sessions') && !Schema::hasColumn('exam_sessions', 'cbt_forced_finish_at')) {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->timestamp('cbt_forced_finish_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exam_sessions') && Schema::hasColumn('exam_sessions', 'cbt_forced_finish_at')) {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->dropColumn('cbt_forced_finish_at');
            });
        }
    }
};
