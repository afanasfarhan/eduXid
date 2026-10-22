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
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('bidang_studi_terakhir')->nullable()->after('pendidikan_terakhir');
            $table->string('pangkat_golongan_terakhir')->nullable()->after('bidang_studi_terakhir');
            $table->string('jabatan_ptk')->nullable()->after('status_kepegawaian');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropColumn(['bidang_studi_terakhir', 'pangkat_golongan_terakhir', 'jabatan_ptk']);
        });
    }
};
