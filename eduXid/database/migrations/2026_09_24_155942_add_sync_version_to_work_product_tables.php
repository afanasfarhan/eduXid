<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * [REWRITE-06.1-FIX] Kolom sync_version untuk LWW lintas jalur.
     *
     * Tabel-tabel ini ditulis oleh DUA sumber (attendance_session HP
     * dengan versi per baris, dan work product batch dengan versi
     * batch). Tanpa kolom ini, applyAttendanceSession melewati LWW
     * (guard hasColumn false) dan batch tidak bisa menandai baris -
     * urutan kedatangan menjadi pemenang (kebocoran nilai).
     *
     * Default 0 + nullable: baris lama dianggap versi terendah -
     * event pertama berikutnya (dari jalur mana pun) menimpanya,
     * dan setelah itu LWW normal berjalan.
     */
    protected array $tables = [
        'assessment_scores',
        'attitude_logs',
        'final_grades',
        'bk_logs',
        'ekskul_grades',
        'kokurikuler_grades',
        'attendances',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'sync_version')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('sync_version')->default(0)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        // Forward-only.
    }
};