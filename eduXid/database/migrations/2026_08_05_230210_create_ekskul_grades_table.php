<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
     Schema::dropIfExists('ekskul_grades');
        Schema::create('ekskul_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('homeroom_id')->constrained('homerooms')->cascadeOnDelete();
            $table->enum('predikat', ['A', 'B', 'C', 'D', 'E'])->default('B');
            $table->text('deskripsi')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ekskul_grades');
    }
};