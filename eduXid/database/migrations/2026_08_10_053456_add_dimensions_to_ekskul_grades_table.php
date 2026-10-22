<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ekskul_grades', function (Blueprint $table) {
            $table->integer('d1')->default(4)->after('predikat');
            $table->integer('d2')->default(4)->after('d1');
            $table->integer('d3')->default(4)->after('d2');
            $table->integer('d4')->default(4)->after('d3');
            $table->integer('d5')->default(4)->after('d4');
            $table->integer('d6')->default(4)->after('d5');
            $table->integer('d7')->default(4)->after('d6');
            $table->integer('d8')->default(4)->after('d7');
        });
    }

    public function down(): void
    {
        Schema::table('ekskul_grades', function (Blueprint $table) {
            $table->dropColumn(['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8']);
        });
    }
};