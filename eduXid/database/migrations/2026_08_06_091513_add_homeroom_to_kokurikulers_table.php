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
        Schema::table('kokurikulers', function (Blueprint $table) {
            $table->foreignId('homeroom_id')->nullable()->constrained('homerooms')->cascadeOnDelete()->after('koordinator_id');
        });
    }

    public function down(): void
    {
        Schema::table('kokurikulers', function (Blueprint $table) {
            $table->dropForeign(['homeroom_id']);
            $table->dropColumn('homeroom_id');
        });
    }
};
