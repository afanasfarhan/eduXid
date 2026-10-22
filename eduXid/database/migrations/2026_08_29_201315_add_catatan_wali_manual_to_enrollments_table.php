<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jalankan migration.
     */
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->boolean('catatan_wali_manual')
                ->default(false)
                ->after('catatan_wali');
        });
    }

    /**
     * Membatalkan migration.
     */
    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('catatan_wali_manual');
        });
    }
};