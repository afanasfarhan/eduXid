<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Idempotent: tabel mungkin sudah dibuat manual via SQL
         * (jalur cepat laptop yang sedang berjalan). Struktur yang
         * dipakai kode: key / value / updated_at.
         */
        if (Schema::hasTable('eduxid_settings')) {
            return;
        }

        Schema::create('eduxid_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eduxid_settings');
    }
};