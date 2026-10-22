<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surat_masuk', function (Blueprint $table) {
            if (!Schema::hasColumn('surat_masuk', 'pengirim')) {
                $table->string('pengirim')->nullable()->after('jenis_surat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('surat_masuk', function (Blueprint $table) {
            if (Schema::hasColumn('surat_masuk', 'pengirim')) {
                $table->dropColumn('pengirim');
            }
        });
    }
};