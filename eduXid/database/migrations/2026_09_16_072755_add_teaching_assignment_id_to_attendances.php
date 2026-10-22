<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('attendances', 'teaching_assignment_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->unsignedBigInteger('teaching_assignment_id')->nullable()->after('student_id');
            });
        }

        if (!Schema::hasIndex('attendances', 'attendances_student_ta_tanggal_index')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['student_id', 'teaching_assignment_id', 'tanggal'], 'attendances_student_ta_tanggal_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('attendances', 'teaching_assignment_id')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropIndex('attendances_student_ta_tanggal_index');
                $table->dropColumn('teaching_assignment_id');
            });
        }
    }
};