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
        Schema::table('attitude_logs', function (Blueprint $table) {
  $table->date('tanggal')->nullable()->after('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('attitude_logs', function (Blueprint $table) {
            $table->dropColumn('tanggal');
        });
    }
};
