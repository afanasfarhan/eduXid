<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('atp_items')) {
            Schema::create('atp_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('teacher_id');
                $table->unsignedBigInteger('subject_id');
                $table->string('semester_id', 32)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('materi', 255);
                $table->text('tujuan_pembelajaran');
                $table->text('indikator_penilaian');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['teacher_id', 'subject_id', 'semester_id'], 'atp_teacher_subject_semester_idx');
                $table->index(['teacher_id', 'subject_id', 'sort_order'], 'atp_teacher_subject_sort_idx');
            });
        }

        if (Schema::hasTable('question_banks') && !Schema::hasColumn('question_banks', 'atp_item_id')) {
            Schema::table('question_banks', function (Blueprint $table) {
                $table->unsignedBigInteger('atp_item_id')->nullable()->after('subject_id')->index();
            });
        }

        if (Schema::hasTable('exams') && !Schema::hasColumn('exams', 'atp_item_ids')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->text('atp_item_ids')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('question_banks') && Schema::hasColumn('question_banks', 'atp_item_id')) {
            Schema::table('question_banks', function (Blueprint $table) {
                $table->dropColumn('atp_item_id');
            });
        }

        if (Schema::hasTable('exams') && Schema::hasColumn('exams', 'atp_item_ids')) {
            Schema::table('exams', function (Blueprint $table) {
                $table->dropColumn('atp_item_ids');
            });
        }

        Schema::dropIfExists('atp_items');
    }
};
