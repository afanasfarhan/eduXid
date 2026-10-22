<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surat_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Pencatat surat (TU)
            $table->string('nomor_surat')->nullable();
            $table->string('jenis_surat');
            $table->string('perihal')->nullable();
            $table->date('tanggal_surat')->nullable();
            $table->json('data_surat'); // Menyimpan semua input form (Nama siswa, isi, dll) dalam bentuk JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surat_records');
    }
};