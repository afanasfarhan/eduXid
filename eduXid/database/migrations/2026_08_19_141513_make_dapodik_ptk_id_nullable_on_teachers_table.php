<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ============================================================
     * UBAH dapodik_ptk_id MENJADI NULLABLE
     * ============================================================
     *
     * Tujuan:
     *
     * 1. GTK dari Dapodik memiliki dapodik_ptk_id.
     * 2. GTK yang ditambahkan admin secara manual tidak wajib
     *    memiliki dapodik_ptk_id.
     * 3. Sinkronisasi Dapodik hanya bekerja pada data yang memiliki
     *    dapodik_ptk_id.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('dapodik_ptk_id')
                ->nullable()
                ->change();
        });
    }

    /**
     * ============================================================
     * ROLLBACK
     * ============================================================
     */
    public function down(): void
    {
        /*
         * Sebelum mengembalikan kolom menjadi NOT NULL,
         * pastikan tidak ada GTK manual yang memiliki
         * dapodik_ptk_id = NULL.
         */
        if (
            \DB::table('teachers')
                ->whereNull('dapodik_ptk_id')
                ->exists()
        ) {
            throw new \RuntimeException(
                'Migration tidak dapat di-rollback karena masih terdapat GTK manual dengan dapodik_ptk_id NULL.'
            );
        }

        Schema::table('teachers', function (Blueprint $table) {
            $table->string('dapodik_ptk_id')
                ->nullable(false)
                ->change();
        });
    }
};