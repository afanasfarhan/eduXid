<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Tambah bobot ke teaching_assignments
        Schema::table('teaching_assignments', function (Blueprint $table) {
            $table->integer('bobot_diagnostik')->default(10)->after('jam_mengajar_per_minggu');
            $table->integer('bobot_formatif')->default(30)->after('bobot_diagnostik');
            $table->integer('bobot_sumatif')->default(60)->after('bobot_formatif');
        });

        // 2. Buat tabel final_grades (Nilai Akhir & Deskripsi)
        Schema::create('final_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teaching_assignment_id')->constrained('teaching_assignments')->cascadeOnDelete();
            $table->integer('nilai_akhir')->nullable();
            $table->text('deskripsi')->nullable();
            $table->timestamps();
        });

        // 3. Tambah catatan wali ke enrollments
        Schema::table('enrollments', function (Blueprint $table) {
            $table->text('catatan_wali')->nullable()->after('pos_y');
        });
    }

    public function down(): void
    {
        Schema::table('teaching_assignments', function (Blueprint $table) {
            $table->dropColumn(['bobot_diagnostik', 'bobot_formatif', 'bobot_sumatif']);
        });
        Schema::dropIfExists('final_grades');
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('catatan_wali');
        });
    }
};
