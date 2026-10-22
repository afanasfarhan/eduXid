<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * =========================================================
     * ADD CLOUD SYNC TOKEN TO SYNC DEVICES
     * =========================================================
     *
     * Token ini adalah device token dari EDUXID CLOUD.
     *
     * Token bersifat:
     * - khusus untuk satu device
     * - digunakan sebagai Bearer token
     * - nullable karena device lama mungkin belum memiliki token
     *
     * Penyimpanan terenkripsi dilakukan di level Model
     * SyncDevice menggunakan Laravel encrypted cast.
     */

    public function up(): void
    {
        Schema::table('sync_devices', function (Blueprint $table) {
            $table->text('sync_token')
                ->nullable()
                ->after('device_name');
        });
    }

    /**
     * =========================================================
     * ROLLBACK
     * =========================================================
     */
    public function down(): void
    {
        Schema::table('sync_devices', function (Blueprint $table) {
            $table->dropColumn('sync_token');
        });
    }
};
