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
        Schema::table('bk_logs', function (Blueprint $table) {
            $table->integer('poin')->default(0)->after('bobot_kasus');
        });
    }

    public function down(): void
    {
        Schema::table('bk_logs', function (Blueprint $table) {
            $table->dropColumn('poin');
        });
    }
};
