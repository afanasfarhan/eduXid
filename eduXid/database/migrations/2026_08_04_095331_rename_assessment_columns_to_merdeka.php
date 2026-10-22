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
            $table->renameColumn('harian', 'diagnostik');
            $table->renameColumn('uts', 'formatif');
            $table->renameColumn('uas', 'sumatif');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_scores', function (Blueprint $table) {
            $table->renameColumn('diagnostik', 'harian');
            $table->renameColumn('formatif', 'uts');
            $table->renameColumn('sumatif', 'uas');
        });
    }
};
