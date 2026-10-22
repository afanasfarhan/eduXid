<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surat_records', function (Blueprint $table) {
            // Tambahkan kolom isi_surat jika belum ada
            if (!Schema::hasColumn('surat_records', 'isi_surat')) {
                $table->text('isi_surat')->nullable()->after('perihal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('surat_records', function (Blueprint $table) {
            if (Schema::hasColumn('surat_records', 'isi_surat')) {
                $table->dropColumn('isi_surat');
            }
        });
    }
};