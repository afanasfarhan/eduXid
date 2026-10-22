<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
         * Hapus unique index lama:
         *
         * student_id + teaching_assignment_id + tanggal
         *
         * Index lama tidak cukup untuk attendance wali kelas
         * karena teaching_assignment_id bernilai NULL.
         */
        DB::statement(
            'DROP INDEX IF EXISTS attendances_student_assignment_date_unique'
        );

        /*
         * Attendance Wali Kelas / Absensi Umum.
         *
         * teaching_assignment_id = NULL
         *
         * Satu siswa hanya boleh memiliki satu record
         * attendance umum pada tanggal yang sama.
         */
        DB::statement(
            'CREATE UNIQUE INDEX attendances_student_date_general_unique
             ON attendances (student_id, tanggal)
             WHERE teaching_assignment_id IS NULL'
        );

        /*
         * Attendance Guru / Mata Pelajaran.
         *
         * teaching_assignment_id IS NOT NULL
         *
         * Satu siswa hanya boleh memiliki satu record
         * attendance untuk assignment tertentu pada tanggal yang sama.
         */
        DB::statement(
            'CREATE UNIQUE INDEX attendances_student_assignment_date_unique
             ON attendances (student_id, teaching_assignment_id, tanggal)
             WHERE teaching_assignment_id IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
         * Hapus partial unique index.
         */
        DB::statement(
            'DROP INDEX IF EXISTS attendances_student_date_general_unique'
        );

        DB::statement(
            'DROP INDEX IF EXISTS attendances_student_assignment_date_unique'
        );

        /*
         * Kembalikan unique index lama.
         */
        DB::statement(
            'CREATE UNIQUE INDEX attendances_student_assignment_date_unique
             ON attendances (student_id, teaching_assignment_id, tanggal)'
        );
    }
};