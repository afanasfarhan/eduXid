<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * [F5 / MG-02] PENAMBAL SKEMA KONTRAK-KOREKSI (schema-tolerant)
     *
     * Kontrak: jawaban disimpan dengan is_benar = NULL (antrean
     * koreksi guru); LAN submit menulis nilai_akhir = NULL.
     * Instalasi dengan skema lama menjadikan kolom-kolom itu NOT NULL
     * → ingest PWA maupun submit LAN gagal dengan Integrity constraint.
     *
     *  1. student_answers.is_benar        → nullable (rebuild bila NOT NULL)
     *  2. exam_sessions.nilai_akhir       → nullable (rebuild bila NOT NULL)
     *  3. exam_sessions.sync_id/feedback  → ditambah bila belum ada
     *     (migrasi integritas lama gagal sebelum sempat menambahkannya)
     *
     * SQLite: nullable change = table-rebuild otomatis oleh Laravel
     * (native sejak 11+). Data terjaga.
     */

    public function up(): void
    {
        /* ---------- 1) student_answers.is_benar → nullable ---------- */
        if (Schema::hasTable('student_answers') && Schema::hasColumn('student_answers', 'is_benar')) {
            $col = collect(DB::select('PRAGMA table_info(student_answers)'))
                ->firstWhere('name', 'is_benar');

            if ($col && (int) $col->notnull === 1) {
                Schema::table('student_answers', function (Blueprint $t) {
                    $t->boolean('is_benar')->nullable()->change();
                });
            }
        }

        if (Schema::hasTable('exam_sessions')) {

            /* ---------- 2) exam_sessions.nilai_akhir → nullable ---------- */
            if (Schema::hasColumn('exam_sessions', 'nilai_akhir')) {
                $col = collect(DB::select('PRAGMA table_info(exam_sessions)'))
                    ->firstWhere('name', 'nilai_akhir');

                if ($col && (int) $col->notnull === 1) {
                    Schema::table('exam_sessions', function (Blueprint $t) {
                        $t->unsignedInteger('nilai_akhir')->nullable()->change();
                    });
                }
            }

            /* ---------- 3) kolom yang mungkin belum terpasang ---------- */
            Schema::table('exam_sessions', function (Blueprint $t) {
                if (!Schema::hasColumn('exam_sessions', 'sync_id')) {
                    $t->string('sync_id')->nullable()->index();
                }
                if (!Schema::hasColumn('exam_sessions', 'feedback')) {
                    $t->text('feedback')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Forward-only.
    }
};