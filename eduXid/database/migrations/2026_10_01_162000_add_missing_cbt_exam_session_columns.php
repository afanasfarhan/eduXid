<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('exam_sessions', 'sync_id')) {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->string('sync_id')->nullable();
            });
        }

        if (!Schema::hasColumn('exam_sessions', 'feedback')) {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->text('feedback')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('exam_sessions', 'feedback')) {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->dropColumn('feedback');
            });
        }

        if (Schema::hasColumn('exam_sessions', 'sync_id')) {
            Schema::table('exam_sessions', function (Blueprint $table) {
                $table->dropColumn('sync_id');
            });
        }
    }
};
