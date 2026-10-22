<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('dapodik_sekolah_id')->unique();
            $table->string('nama');
            $table->string('npsn')->nullable();
            $table->string('bentuk_pendidikan')->nullable();
            $table->string('status_sekolah')->nullable();
            $table->text('alamat_jalan')->nullable();
            $table->string('kode_pos')->nullable();
            $table->string('nomor_telepon')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('kecamatan')->nullable();
            $table->string('kabupaten_kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};