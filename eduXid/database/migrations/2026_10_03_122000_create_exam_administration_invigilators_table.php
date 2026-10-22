<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exam_administration_invigilators')) {
            return;
        }

        Schema::create('exam_administration_invigilators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_administration_id')
                ->constrained('exam_administrations')
                ->cascadeOnDelete();
            $table->foreignId('exam_administration_schedule_id')
                ->constrained('exam_administration_schedules')
                ->cascadeOnDelete();
            $table->foreignId('exam_administration_room_id')
                ->constrained('exam_administration_rooms')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('supervisor_no');
            $table->unsignedBigInteger('teacher_id')->nullable()->index();
            $table->string('name', 150);
            $table->string('nip', 50)->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'exam_administration_schedule_id',
                    'exam_administration_room_id',
                    'supervisor_no',
                ],
                'exam_admin_invigilator_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_administration_invigilators');
    }
};
