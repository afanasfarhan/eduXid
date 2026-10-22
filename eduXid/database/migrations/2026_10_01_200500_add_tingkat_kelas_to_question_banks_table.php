<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('question_banks')
            && !Schema::hasColumn('question_banks', 'tingkat_kelas')
        ) {
            Schema::table('question_banks', function (Blueprint $table) {
                $table->unsignedTinyInteger('tingkat_kelas')
                    ->nullable();
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('question_banks')
            && Schema::hasColumn('question_banks', 'tingkat_kelas')
        ) {
            Schema::table('question_banks', function (Blueprint $table) {
                $table->dropColumn('tingkat_kelas');
            });
        }
    }
};
