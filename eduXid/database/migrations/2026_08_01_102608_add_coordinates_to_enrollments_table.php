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
     Schema::table('enrollments', function (Blueprint $table) {
         $table->integer('pos_x')->nullable()->after('jenis_pendaftaran');
         $table->integer('pos_y')->nullable()->after('pos_x');
     });
 }

    /**
     * Reverse the migrations.
     */
 public function down(): void
 {
     Schema::table('enrollments', function (Blueprint $table) {
         $table->dropColumn(['pos_x', 'pos_y']);
     });
 }
};
