<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_scores', function (Blueprint $table) {
            $table->string('jam_ke', 20)->nullable();       // "Jam ke-3" dsb. (opsional)
            $table->string('pukul', 50)->nullable();        // "07.30 - 09.00" (opsional)
            $table->text('catatan_siswa')->nullable();      // catatan per siswa (opsional)
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scores', function (Blueprint $table) {
            $table->dropColumn(['jam_ke', 'pukul', 'catatan_siswa']);
        });
    }
};