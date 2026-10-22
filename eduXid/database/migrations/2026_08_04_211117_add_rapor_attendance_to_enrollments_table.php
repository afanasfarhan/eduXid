<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya tambahkan kolom absensi jika belum ada
        if (!Schema::hasColumn('enrollments', 'rapor_sakit')) {
            Schema::table('enrollments', function (Blueprint $table) {
                $table->integer('rapor_sakit')->default(0)->after('catatan_wali')->nullable();
                $table->integer('rapor_izin')->default(0)->after('rapor_sakit')->nullable();
                $table->integer('rapor_alpa')->default(0)->after('rapor_izin')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn(['rapor_sakit', 'rapor_izin', 'rapor_alpa']);
        });
    }
};