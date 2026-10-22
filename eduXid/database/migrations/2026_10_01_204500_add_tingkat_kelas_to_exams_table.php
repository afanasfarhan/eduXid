<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('exams')) {
            return;
        }

        if (!Schema::hasColumn('exams', 'tingkat_kelas')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->unsignedTinyInteger('tingkat_kelas')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exams') && Schema::hasColumn('exams', 'tingkat_kelas')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('tingkat_kelas');
            });
        }
    }
};
