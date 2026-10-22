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
        /*
         * LMS submission sekarang menggunakan:
         *
         * users.login_id
         *        ↓
         * lms_assignment_submissions.student_id
         *
         * Jadi student_id tidak lagi menjadi foreign key
         * ke students.id.
         *
         * Tabel saat ini masih kosong, sehingga struktur dapat
         * dibuat ulang tanpa migrasi data lama.
         */

        Schema::dropIfExists('lms_assignment_submissions');

        Schema::create('lms_assignment_submissions', function (Blueprint $table) {
            $table->id();

            $table->string('sync_id');

            $table->foreignId('assignment_id')
                ->constrained('lms_assignments')
                ->cascadeOnDelete();

            /*
             * Identitas siswa untuk LMS:
             * users.login_id
             *
             * BUKAN students.id.
             */
            $table->string('student_id');

            $table->text('answer_text')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();

            $table->dateTime('submitted_at')->nullable();

            $table->decimal('score')->nullable();

            $table->text('teacher_feedback')->nullable();

            $table->string('status')
                ->default('draft');

            $table->string('sync_status')
                ->default('local');

            $table->integer('sync_version')
                ->default(1);

            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_assignment_submissions');

        Schema::create('lms_assignment_submissions', function (Blueprint $table) {
            $table->id();

            $table->string('sync_id');

            $table->foreignId('assignment_id')
                ->constrained('lms_assignments')
                ->cascadeOnDelete();

            $table->foreignId('student_id')
                ->constrained('students')
                ->cascadeOnDelete();

            $table->text('answer_text')->nullable();

            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->integer('file_size')->nullable();

            $table->dateTime('submitted_at')->nullable();

            $table->decimal('score')->nullable();

            $table->text('teacher_feedback')->nullable();

            $table->string('status')
                ->default('draft');

            $table->string('sync_status')
                ->default('local');

            $table->integer('sync_version')
                ->default(1);

            $table->dateTime('last_synced_at')->nullable();

            $table->timestamps();
        });
    }
};
