<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lms_sync_records', function (Blueprint $table) {
            $table->string('target_device_id')
                ->nullable()
                ->after('node_type')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lms_sync_records', function (Blueprint $table) {
            $table->dropIndex([
                'target_device_id',
            ]);

            $table->dropColumn('target_device_id');
        });
    }
};
