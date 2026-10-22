<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * ============================================================
     * [FASE-0 / P3-12 / MG-01] KOLOM INTEGRITAS SINKRONISASI — LMS
     * ============================================================
     *
     * Menutup celah verifikasi MG-01: tabel LMS wajib punya
     * sync_version agar guard LWW di LmsSyncApplyService aktif dan
     * stamping versi (SyncApplier::applyTeacherGrade, grade() laptop,
     * bump ExamController/LmsAssignmentController) tidak pernah crash
     * "column not found".
     *
     * + final_grades.d1..d8 [R-03]: jalur sikap prioritas-1 rapor
     *   (akan diisi produsen final_grade di Fase 2).
     *
     * Idempoten (hasTable/hasColumn) & forward-only — pola migrasi
     * sync_version yang sudah ada dipertahankan.
     */

    protected array $syncVersionTables = [
        'lms_courses',
        'lms_topics',
        'lms_materials',
        'lms_assignments',
        'lms_assignment_submissions',
        'exams',
        'exam_sessions',
    ];

    protected array $finalGradeDimensions = ['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8'];

    public function up(): void
    {
        foreach ($this->syncVersionTables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'sync_version')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('sync_version')->default(0)->nullable();
                });
            }
        }

        if (Schema::hasTable('final_grades')) {
            $missing = array_filter(
                $this->finalGradeDimensions,
                fn (string $col): bool => !Schema::hasColumn('final_grades', $col)
            );

            if ($missing !== []) {
                Schema::table('final_grades', function (Blueprint $t) use ($missing) {
                    foreach ($missing as $col) {
                        $t->unsignedTinyInteger($col)->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        // Forward-only.
    }
};