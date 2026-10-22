<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homerooms', function (Blueprint $table) {
            $table->id();
            $table->string('dapodik_rombel_id')->unique();
            $table->string('nama');
            $table->string('tingkat_pendidikan')->nullable();
            $table->string('semester_id')->nullable();
            $table->string('jenis_rombel')->nullable();
            $table->string('kurikulum')->nullable();
            $table->foreignId('homeroom_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('jurusan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homerooms');
    }
};