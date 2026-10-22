<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('modul_ajar')) {
            return;
        }
        Schema::create('modul_ajar', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedTinyInteger('grade_level');
            $table->unsignedBigInteger('atp_item_id')->nullable();
            $table->string('semester_id', 32)->nullable();
            $table->string('title', 255);
            $table->string('phase', 8)->nullable();
            $table->unsignedSmallInteger('duration_jp')->default(2);
            $table->string('status', 16)->default('draft');
            $table->string('generation_source', 16)->default('manual');
            $table->text('content');
            $table->timestamps();
            $table->index(['teacher_id','subject_id','grade_level'], 'ma_teacher_subject_grade_idx');
            $table->index(['teacher_id','semester_id'], 'ma_teacher_semester_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modul_ajar');
    }
};
