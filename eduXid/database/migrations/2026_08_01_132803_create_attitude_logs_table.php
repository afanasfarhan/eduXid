<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attitude_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teaching_assignment_id')->default(1)->constrained('teaching_assignments')->cascadeOnDelete();
            // 8 Dimensi Sikap (Default 3 = Cukup)
            $table->integer('d1')->default(3); // Keimanan
            $table->integer('d2')->default(3); // Kewargaan
            $table->integer('d3')->default(3); // Penalaran
            $table->integer('d4')->default(3); // Kreativitas
            $table->integer('d5')->default(3); // Kolaborasi
            $table->integer('d6')->default(3); // Kemandirian
            $table->integer('d7')->default(3); // Kesehatan
            $table->integer('d8')->default(3); // Komunikasi
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attitude_logs');
    }
};