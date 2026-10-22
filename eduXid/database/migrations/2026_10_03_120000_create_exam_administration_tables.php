<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_administrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('academic_year')->nullable();
            $table->string('semester')->nullable();
            $table->unsignedBigInteger('semester_id')->nullable()->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
        });

        Schema::create('exam_administration_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_administration_id')
                ->constrained('exam_administrations')
                ->cascadeOnDelete();
            $table->string('title');
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->string('mode', 20)->default('paper');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['exam_administration_id', 'exam_date']);
        });

        Schema::create('exam_administration_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_administration_id')
                ->constrained('exam_administrations')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('homeroom_id')->index();
            $table->string('room_name');
            $table->unsignedInteger('capacity_snapshot')->default(0);
            $table->unsignedTinyInteger('seat_columns')->default(6);
            $table->timestamps();

            $table->unique(
                ['exam_administration_id', 'homeroom_id'],
                'exam_admin_room_unique'
            );
        });

        Schema::create('exam_administration_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_administration_id')
                ->constrained('exam_administrations')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('student_id')->index();
            $table->foreignId('exam_administration_room_id')
                ->constrained('exam_administration_rooms')
                ->cascadeOnDelete();
            $table->unsignedInteger('seat_number')->nullable();
            $table->timestamps();

            $table->unique(
                ['exam_administration_id', 'student_id'],
                'exam_admin_participant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_administration_participants');
        Schema::dropIfExists('exam_administration_rooms');
        Schema::dropIfExists('exam_administration_schedules');
        Schema::dropIfExists('exam_administrations');
    }
};
