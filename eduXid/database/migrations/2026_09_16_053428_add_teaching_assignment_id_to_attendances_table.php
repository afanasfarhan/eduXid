<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan teaching_assignment_id ke attendance.
     *
     * Kolom dibuat nullable karena attendance lama belum memiliki
     * informasi teaching assignment.
     */
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('teaching_assignment_id')
                ->nullable()
                ->after('student_id');

            $table->unique(
                ['student_id', 'teaching_assignment_id', 'tanggal'],
                'attendances_student_assignment_date_unique'
            );
        });
    }

    /**
     * Rollback migration.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(
                'attendances_student_assignment_date_unique'
            );

            $table->dropColumn('teaching_assignment_id');
        });
    }
};