<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_sync_records', function (Blueprint $table) {
            if (Schema::hasColumn('lms_sync_records', 'error_message')) {
                $table->renameColumn('error_message', 'last_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lms_sync_records', function (Blueprint $table) {
            if (Schema::hasColumn('lms_sync_records', 'last_error')) {
                $table->renameColumn('last_error', 'error_message');
            }
        });
    }
};