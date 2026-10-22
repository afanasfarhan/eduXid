<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bk_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('tanggal');
            $table->text('permasalahan');
            $table->text('penanganan');
            $table->enum('bobot_kasus', ['Ringan', 'Sedang', 'Berat'])->default('Ringan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bk_logs');
    }
};