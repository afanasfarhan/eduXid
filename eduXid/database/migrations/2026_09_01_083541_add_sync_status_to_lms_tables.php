<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'lms_courses',
            'lms_topics',
            'lms_materials',
            'lms_assignments',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'sync_status')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->string('sync_status')
                        ->default('pending')
                        ->after('is_published');
                });
            }
        }
    }

    public function down(): void
    {
        $tables = [
            'lms_courses',
            'lms_topics',
            'lms_materials',
            'lms_assignments',
        ];

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'sync_status')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('sync_status');
                });
            }
        }
    }
};