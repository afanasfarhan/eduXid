<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ekskul_grades', function (Blueprint $table) {
            $table->integer('bintang')->default(4)->after('predikat'); // Default 4 (B)
        });
    }

    public function down(): void
    {
        Schema::table('ekskul_grades', function (Blueprint $table) {
            $table->dropColumn('bintang');
        });
    }
};
