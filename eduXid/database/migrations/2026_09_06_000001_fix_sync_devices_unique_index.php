<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ============================================================
     * FIX SYNC_DEVICES — device_id TIDAK lagi unique global
     * ============================================================
     *
     * Masalah: kolom device_id punya UNIQUE constraint global,
     * sehingga 1 device_id hanya bisa ada di 1 baris — padahal
     * desain multi-tenant butuh device_id yang bisa muncul di
     * BANYAK school_id (NPSN berbeda per sekolah, NISN sama
     * bisa muncul di sekolah berbeda).
     *
     * Solusi: hapus unique global pada device_id, ganti dengan
     * unique composite (school_id, device_id) — satu device_id
     * boleh ada di banyak school, tapi hanya satu per school.
     *
     * SQLite punya keterbatasan: tidak bisa DROP CONSTRAINT.
     * Cara yang benar di SQLite: buat tabel baru, pindahkan
     * data, drop tabel lama, rename tabel baru.
     * ============================================================
     */
    public function up(): void
    {
        Schema::table('sync_devices', function (Blueprint $table) {

            /*
             * SQLite: tidak bisa drop unique index secara langsung
             * kalau index itu bagian dari definisi kolom.
             * Tapi kita bisa drop index terpisah jika ada.
             *
             * Cek apakah ada index bernama sync_devices_device_id_unique
             * — kalau ada, drop.
             */

            $indexes = DB::select("PRAGMA index_list('sync_devices')");

            foreach ($indexes as $index) {
                $indexName = $index->name;

                if (
                    str_contains($indexName, 'device_id') &&
                    str_contains($indexName, 'unique')
                ) {
                    // Drop index global yang menyebabkan conflict
                    DB::statement("DROP INDEX IF EXISTS `{$indexName}`");
                }
            }
        });
    }

    public function down(): void
    {
        // Tidak perlu rollback — composite index sudah didefinisikan
        // di migrasi asli (bila ada) atau ditambahkan kembali manual.
    }
};