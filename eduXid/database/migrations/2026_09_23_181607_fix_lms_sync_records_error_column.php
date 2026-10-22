<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasOld = Schema::hasColumn('lms_sync_records', 'error_message');
        $hasNew = Schema::hasColumn('lms_sync_records', 'last_error');

        if ($hasOld && $hasNew) {
            DB::statement(
                'UPDATE lms_sync_records
                 SET last_error = error_message
                 WHERE last_error IS NULL
                 AND error_message IS NOT NULL'
            );

            Schema::table('lms_sync_records', function (Blueprint $table) {
                $table->dropColumn('error_message');
            });
        } elseif ($hasOld && !$hasNew) {
            Schema::table('lms_sync_records', function (Blueprint $table) {
                $table->renameColumn('error_message', 'last_error');
            });
        }
    }

    public function down(): void
    {
    }
};