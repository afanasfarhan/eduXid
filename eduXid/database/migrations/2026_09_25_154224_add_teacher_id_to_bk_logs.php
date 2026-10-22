<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * [SCOPE-TA] bk_logs.teacher_id - pemilik log BK, dipakai:
         *   1. BkController (stamp saat store, owner saat destroy)
         *   2. Worker SCOPE-TA (fan-out bk_log_batch hanya ke guru
         *      pemilik - dulu broadcast ke semua guru = kebocoran
         *      data BK siswa)
         * Bila BK dibuat oleh wali kelas, isi dengan user_id wali
         * (keputusan produk terpisah).
         */
        Schema::table('bk_logs', function (Blueprint $t) {
            if (!Schema::hasColumn('bk_logs', 'teacher_id')) {
                $t->unsignedBigInteger('teacher_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};