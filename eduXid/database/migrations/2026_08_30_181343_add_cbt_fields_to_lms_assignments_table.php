<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kemampuan CBT pada tugas LMS.
     *
     * [IDEMPOTEN-FIX] Kolom assignment_type & exam_id ternyata juga
     * sudah didefinisikan di migrasi create table — tanpa guard,
     * migrate:fresh gagal "duplicate column name: assignment_type".
     * Dengan guard, migrasi ini aman dijalankan di kondisi DB mana pun.
     */
    public function up(): void
    {
        Schema::table('lms_assignments', function (Blueprint $table) {

            // Jenis tugas:
            // text = tugas teks
            // file = tugas file
            // text_file = teks + file
            // cbt = tugas berbasis CBT
            if (!Schema::hasColumn('lms_assignments', 'assignment_type')) {
                $table->string('assignment_type')
                    ->default('regular')
                    ->after('title');
            }

            // Menghubungkan tugas LMS dengan paket CBT
            if (!Schema::hasColumn('lms_assignments', 'exam_id')) {
                $table->foreignId('exam_id')
                    ->nullable()
                    ->after('assignment_type')
                    ->constrained('exams')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Menghapus integrasi CBT.
     *
     * [IDEMPOTEN-FIX] Guard juga di down() — dropForeign/dropColumn
     * pada kolom yang tidak ada melempar error di SQLite.
     */
    public function down(): void
    {
        if (
            Schema::hasColumn('lms_assignments', 'exam_id')
            || Schema::hasColumn('lms_assignments', 'assignment_type')
        ) {
            Schema::table('lms_assignments', function (Blueprint $table) {

                if (Schema::hasColumn('lms_assignments', 'exam_id')) {
                    // Foreign key SQLite dibuat otomatis bernama
                    // lms_assignments_exam_id_foreign — cek dulu bila ada.
                    try {
                        $table->dropForeign(['exam_id']);
                    } catch (\Throwable $e) {
                        // SQLite: foreign key di-drop bersama kolom.
                    }
                }

                $columns = [];

                if (Schema::hasColumn('lms_assignments', 'exam_id')) {
                    $columns[] = 'exam_id';
                }

                if (Schema::hasColumn('lms_assignments', 'assignment_type')) {
                    $columns[] = 'assignment_type';
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};