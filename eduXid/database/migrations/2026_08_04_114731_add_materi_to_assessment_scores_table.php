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
        Schema::table('assessment_scores', function (Blueprint $table) {
            $table->string('materi')->nullable()->after('tanggal');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scores', function (Blueprint $table) {
            $table->dropColumn('materi');
        });
    }
};
