<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kartu_pelajar_settings', function (Blueprint $table) {

            $table->id();

            $table->string('logo_kiri')->nullable();
            $table->string('logo_kanan')->nullable();

            $table->string('jabatan_penanda_tangan')->nullable();
            $table->string('nama_penanda_tangan')->nullable();
            $table->string('nip_penanda_tangan')->nullable();

            $table->string('tempat_penanda_tangan')->nullable();
            $table->date('tanggal_penanda_tangan')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kartu_pelajar_settings');
    }
};