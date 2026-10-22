<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('jenis_surat')->nullable();
            $table->string('nomor_surat')->nullable();
            $table->string('perihal')->nullable();
            $table->date('tanggal_surat')->nullable();

            $table->string('tujuan')->nullable();
            $table->string('penerima_nama')->nullable();
            $table->string('penerima_tipe')->nullable();

            $table->string('penandatangan_nama')->nullable();
            $table->string('penandatangan_jabatan')->nullable();

            $table->json('data')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surats');
    }
};