<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('atp_items')) {
            Schema::create('atp_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('sync_id')->nullable()->unique();
                $table->unsignedBigInteger('teacher_id');
                $table->unsignedBigInteger('subject_id');
                $table->unsignedTinyInteger('grade_level')->nullable();
                $table->string('semester_id', 32)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('materi', 255);
                $table->text('tujuan_pembelajaran');
                $table->text('indikator_penilaian');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('sync_version')->default(1);
                $table->string('sync_status', 20)->default('pending');
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->index(
                    ['teacher_id', 'subject_id', 'grade_level', 'semester_id'],
                    'atp_teacher_subject_grade_sem_idx'
                );
            });
        } else {
            Schema::table('atp_items', function (Blueprint $table) {
                if (!Schema::hasColumn('atp_items', 'sync_id')) {
                    $table->uuid('sync_id')->nullable()->unique();
                }
                if (!Schema::hasColumn('atp_items', 'grade_level')) {
                    $table->unsignedTinyInteger('grade_level')->nullable()->index();
                }
                if (!Schema::hasColumn('atp_items', 'sync_version')) {
                    $table->unsignedBigInteger('sync_version')->default(1);
                }
                if (!Schema::hasColumn('atp_items', 'sync_status')) {
                    $table->string('sync_status', 20)->default('pending');
                }
                if (!Schema::hasColumn('atp_items', 'last_synced_at')) {
                    $table->timestamp('last_synced_at')->nullable();
                }
            });
        }

        DB::table('atp_items')
            ->whereNull('sync_id')
            ->orderBy('id')
            ->get()
            ->each(function ($row) {
                DB::table('atp_items')
                    ->where('id', $row->id)
                    ->update([
                        'sync_id' => (string) Str::uuid(),
                        'sync_version' => max(1, (int) ($row->sync_version ?? 1)),
                        'sync_status' => $row->sync_status ?? 'pending',
                    ]);
            });

        // Backfill tingkat kelas hanya jika satu guru-mapel memang hanya mengajar satu tingkat.
        if (
            Schema::hasTable('teaching_assignments')
            && Schema::hasTable('homerooms')
            && Schema::hasColumn('homerooms', 'tingkat_pendidikan')
        ) {
            $legacy = DB::table('atp_items')
                ->whereNull('grade_level')
                ->select('teacher_id', 'subject_id')
                ->distinct()
                ->get();

            foreach ($legacy as $scope) {
                $rawLevels = DB::table('teaching_assignments')
                    ->join('homerooms', 'homerooms.id', '=', 'teaching_assignments.homeroom_id')
                    ->where('teaching_assignments.teacher_id', $scope->teacher_id)
                    ->where('teaching_assignments.subject_id', $scope->subject_id)
                    ->pluck('homerooms.tingkat_pendidikan')
                    ->map(function ($value) {
                        if (is_numeric($value)) {
                            $n = (int) $value;
                            return ($n >= 1 && $n <= 13) ? $n : null;
                        }

                        if (preg_match('/\b(1[0-3]|[1-9])\b/', (string) $value, $m)) {
                            return (int) $m[1];
                        }

                        return null;
                    })
                    ->filter()
                    ->unique()
                    ->values();

                if ($rawLevels->count() === 1) {
                    DB::table('atp_items')
                        ->where('teacher_id', $scope->teacher_id)
                        ->where('subject_id', $scope->subject_id)
                        ->whereNull('grade_level')
                        ->update(['grade_level' => (int) $rawLevels->first()]);
                }
            }
        }

        if (Schema::hasTable('question_banks') && !Schema::hasColumn('question_banks', 'atp_item_id')) {
            Schema::table('question_banks', function (Blueprint $table) {
                $table->unsignedBigInteger('atp_item_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('exams') && !Schema::hasColumn('exams', 'atp_item_ids')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->text('atp_item_ids')->nullable();
            });
        }

        if (Schema::hasTable('lms_materials') && !Schema::hasColumn('lms_materials', 'atp_item_id')) {
            Schema::table('lms_materials', function (Blueprint $table) {
                $table->unsignedBigInteger('atp_item_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('lms_assignments') && !Schema::hasColumn('lms_assignments', 'atp_item_id')) {
            Schema::table('lms_assignments', function (Blueprint $table) {
                $table->unsignedBigInteger('atp_item_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // Tidak menghapus data ATP pada rollback otomatis.
    }
};
