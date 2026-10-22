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
        Schema::create('patches', function (Blueprint $table) {

            $table->id();

            /*
             * Versi patch.
             *
             * Contoh:
             * 1.0.1
             * 1.0.2
             * 1.1.0
             */
            $table->string('version', 50)->unique();

            /*
             * Nama patch.
             */
            $table->string('name');

            /*
             * Penjelasan perubahan patch.
             */
            $table->text('description')->nullable();

            /*
             * Jenis update.
             *
             * patch  = perbaikan kecil
             * update = pembaruan fitur
             * major  = perubahan besar
             */
            $table->string('type', 20)->default('patch');

            /*
             * Status pemasangan.
             *
             * pending   = belum dipasang
             * installed = sudah dipasang
             * failed    = gagal
             */
            $table->string('status', 20)->default('pending');

            /*
             * Waktu patch berhasil dipasang.
             */
            $table->timestamp('installed_at')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Membatalkan migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('patches');
    }
};
