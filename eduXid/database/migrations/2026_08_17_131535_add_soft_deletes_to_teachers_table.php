<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tambah kolom deleted_at ke tabel teachers
     * untuk mendukung fitur SoftDeletes.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            // softDeletes() menambah kolom deleted_at (nullable, timestamp)
            // after('updated_at') menempatkan kolom setelah updated_at
            $table->softDeletes()->after('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};