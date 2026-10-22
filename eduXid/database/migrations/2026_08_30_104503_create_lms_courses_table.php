<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * =========================================================
     * CREATE TABLE
     * =========================================================
     */
    public function up(): void
    {
        Schema::create('lms_courses', function (Blueprint $table) {

            /*
             * =====================================================
             * PRIMARY KEY
             * =====================================================
             */
            $table->id();

            /*
             * =====================================================
             * GLOBAL SYNC ID
             * =====================================================
             *
             * ID ini digunakan sebagai identitas global LMS
             * antar perangkat.
             *
             * Contoh:
             * 550e8400-e29b-41d4-a716-446655440000
             */
            $table->uuid('sync_id')
                ->unique();

            /*
             * =====================================================
             * GURU
             * =====================================================
             */
            $table->foreignId('teacher_id')
                ->nullable()
                ->constrained('teachers')
                ->nullOnDelete();

            /*
             * =====================================================
             * MATA PELAJARAN
             * =====================================================
             */
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('subjects')
                ->nullOnDelete();

            /*
             * =====================================================
             * ROMBEL
             * =====================================================
             */
            $table->foreignId('homeroom_id')
                ->nullable()
                ->constrained('homerooms')
                ->nullOnDelete();

            /*
             * =====================================================
             * INFORMASI KELAS LMS
             * =====================================================
             */
            $table->string('name');

            $table->text('description')
                ->nullable();

            /*
             * =====================================================
             * TAHUN AJARAN
             * =====================================================
             */
            $table->string('academic_year')
                ->nullable();

            /*
             * =====================================================
             * SEMESTER
             * =====================================================
             */
            $table->string('semester')
                ->nullable();

            /*
             * =====================================================
             * STATUS PUBLIKASI
             * =====================================================
             */
            $table->boolean('is_published')
                ->default(false);

            /*
             * =====================================================
             * VERSION SINKRONISASI
             * =====================================================
             *
             * Digunakan untuk menentukan apakah data lokal
             * lebih lama atau lebih baru daripada data yang
             * diterima dari Sync Server.
             */
            $table->unsignedBigInteger('sync_version')
                ->default(1);

            /*
             * =====================================================
             * WAKTU TERAKHIR SINKRONISASI
             * =====================================================
             */
            $table->timestamp('last_synced_at')
                ->nullable();

            /*
             * =====================================================
             * TIMESTAMP LARAVEL
             * =====================================================
             */
            $table->timestamps();

            /*
             * =====================================================
             * INDEX
             * =====================================================
             *
             * Membantu proses sinkronisasi dan pencarian data.
             */
            $table->index(
                'sync_version',
                'lms_courses_sync_version_index'
            );

            $table->index(
                'teacher_id',
                'lms_courses_teacher_id_index'
            );

            $table->index(
                'subject_id',
                'lms_courses_subject_id_index'
            );

            $table->index(
                'homeroom_id',
                'lms_courses_homeroom_id_index'
            );
        });
    }

    /**
     * =========================================================
     * DROP TABLE
     * =========================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_courses');
    }
};
