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
        Schema::create('lms_sync_records', function (Blueprint $table) {

            /*
             * =================================================
             * PRIMARY KEY
             * =================================================
             *
             * ID lokal database.
             *
             * TIDAK digunakan sebagai identitas sinkronisasi.
             */
            $table->id();


            /*
             * =================================================
             * GLOBAL SYNC ID
             * =================================================
             *
             * Identitas unik record sinkronisasi.
             *
             * Contoh:
             * 550e8400-e29b-41d4-a716-446655440000
             */
            $table->uuid('sync_id')->unique();


            /*
             * =================================================
             * NODE
             * =================================================
             *
             * Perangkat yang membuat perubahan.
             *
             * Contoh:
             *
             * GURU-001-PC
             * SISWA-001-HP
             */
            $table->string('node_id')->index();

            $table->enum('node_type', [
                'teacher',
                'student',
            ]);


            /*
             * =================================================
             * ENTITY
             * =================================================
             *
             * Jenis data LMS yang berubah.
             *
             * Contoh:
             *
             * lms_course
             * lms_topic
             * lms_material
             * lms_assignment
             * lms_assignment_submission
             */
            $table->string('entity_type')->index();


            /*
             * =================================================
             * ENTITY ID
             * =================================================
             *
             * ID GLOBAL objek LMS.
             *
             * BUKAN ID lokal database.
             *
             * Nilainya sama dengan:
             *
             * lms_courses.sync_id
             * lms_topics.sync_id
             * dst.
             */
            $table->uuid('entity_id');


            /*
             * =================================================
             * OPERATION
             * =================================================
             *
             * Perubahan yang terjadi.
             */
            $table->enum('operation', [
                'create',
                'update',
                'delete',
            ]);


            /*
             * =================================================
             * VERSION
             * =================================================
             *
             * Versi entity ketika perubahan dibuat.
             */
            $table->unsignedInteger('version')->default(1);


            /*
             * =================================================
             * PAYLOAD
             * =================================================
             *
             * Snapshot data yang dikirim ke Sync Server.
             *
             * Contoh:
             *
             * {
             *     "sync_id": "...",
             *     "title": "Materi Gelombang",
             *     "sync_version": 3
             * }
             */
            $table->json('payload')->nullable();


            /*
             * =================================================
             * STATUS
             * =================================================
             *
             * pending
             *     Menunggu dikirim.
             *
             * processing
             *     Sedang diproses.
             *
             * synced
             *     Berhasil dikirim.
             *
             * failed
             *     Gagal dikirim.
             */
            $table->enum('status', [
                'pending',
                'processing',
                'synced',
                'failed',
            ])->default('pending');


            /*
             * =================================================
             * ERROR
             * =================================================
             *
             * Menyimpan pesan error terakhir.
             */
            $table->text('error_message')->nullable();


            /*
             * =================================================
             * SYNCED AT
             * =================================================
             */
            $table->timestamp('synced_at')->nullable();


            /*
             * =================================================
             * TIMESTAMPS
             * =================================================
             */
            $table->timestamps();


            /*
             * =================================================
             * INDEX
             * =================================================
             *
             * Mempercepat pencarian antrean sinkronisasi.
             */
            $table->index([
                'node_id',
                'status',
            ]);


            /*
             * Mempercepat pencarian entity.
             */
            $table->index([
                'entity_type',
                'entity_id',
            ]);


            /*
             * Mempercepat pencarian record berdasarkan
             * status dan entity.
             */
            $table->index([
                'entity_type',
                'status',
            ]);
        });
    }


    /**
     * =========================================================
     * DROP TABLE
     * =========================================================
     */
    public function down(): void
    {
        Schema::dropIfExists(
            'lms_sync_records'
        );
    }
};