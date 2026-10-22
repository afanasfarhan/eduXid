<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ============================================================
     * COMPOSITE UNIQUE — device_id unik PER SCHOOL, bukan global
     * ============================================================
     *
     * SQLite tidak mendukung ALTER TABLE ADD CONSTRAINT secara
     * langsung. Cara paling aman: drop index lama (jika ada),
     * lalu buat unique index composite baru via SQL statement.
     * ============================================================
     */

    public function up(): void
    {
        /*
         * Cari dan hapus index unique global pada device_id
         * (bila ada dari migrasi lama).
         */
        $indexes = DB::select(
            "SELECT name FROM sqlite_master
             WHERE type = 'index'
             AND tbl_name = 'sync_devices'
             AND sql LIKE '%device_id%'"
        );

        foreach ($indexes as $index) {
            $indexName = $index->name;

            /*
             * Skip primary key & index composite.
             */
            if (
                str_contains($indexName, 'sync_devices_primary')
                || str_contains($indexName, 'school_id_device_id')
            ) {
                continue;
            }

            /*
             * Drop index yang memuat unique device_id saja.
             */
            DB::statement("DROP INDEX IF EXISTS `{$indexName}`");
        }


        /*
         * Buat composite unique: (school_id, device_id).
         */
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '
            . 'sync_devices_school_device_unique '
            . 'ON sync_devices (school_id, device_id)'
        );
    }

    public function down(): void
    {
        /*
         * Kembalikan ke unique global device_id (bila perlu).
         */
        DB::statement(
            'DROP INDEX IF EXISTS sync_devices_school_device_unique'
        );

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '
            . 'sync_devices_device_id_unique '
            . 'ON sync_devices (device_id)'
        );
    }
};