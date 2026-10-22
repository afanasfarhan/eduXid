<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('exam_administration_rooms')
            && !Schema::hasColumn('exam_administration_rooms', 'seat_columns')
        ) {
            Schema::table('exam_administration_rooms', function (Blueprint $table) {
                $table->unsignedTinyInteger('seat_columns')->default(6);
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('exam_administration_rooms')
            && Schema::hasColumn('exam_administration_rooms', 'seat_columns')
        ) {
            Schema::table('exam_administration_rooms', function (Blueprint $table) {
                $table->dropColumn('seat_columns');
            });
        }
    }
};
