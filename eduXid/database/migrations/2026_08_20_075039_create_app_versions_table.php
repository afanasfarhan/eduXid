<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Membuat tabel penyimpan versi aplikasi.
     */
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();

            /*
             * Versi aplikasi.
             *
             * Contoh:
             * 1.0.0
             * 1.0.1
             * 1.1.0
             */
            $table->string('version', 50)->unique();

            /*
             * Menandai versi yang sedang aktif.
             *
             * Hanya satu versi yang boleh aktif.
             */
            $table->boolean('is_current')->default(false);

            /*
             * Keterangan versi.
             */
            $table->string('name')->nullable();

            $table->text('description')->nullable();

            /*
             * Waktu versi mulai digunakan.
             */
            $table->timestamp('activated_at')->nullable();

            $table->timestamps();

            $table->index('is_current');
        });
    }

    /**
     * Menghapus tabel app_versions.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
