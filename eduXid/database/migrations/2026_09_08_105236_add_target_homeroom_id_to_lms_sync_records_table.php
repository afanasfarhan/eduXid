<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambahkan target rombel pada outbox sinkronisasi LMS.
     *
     * NULL berarti:
     * - bukan target rombel tertentu, atau
     * - record lama/broadcast umum.
     *
     * Nilai diisi dengan homeroom_id untuk:
     * - lms_course
     * - lms_topic
     * - lms_material
     * - lms_assignment
     * - cbt_exam
     */
    public function up(): void
    {
        Schema::table('lms_sync_records', function (Blueprint $table) {
            $table->unsignedBigInteger('target_homeroom_id')
                ->nullable()
                ->after('target_device_id');

            $table->index(
                'target_homeroom_id',
                'idx_lms_sync_records_target_homeroom'
            );
        });
    }

    /**
     * Hapus target rombel saat rollback migration.
     */
    public function down(): void
    {
        Schema::table('lms_sync_records', function (Blueprint $table) {
            $table->dropIndex(
                'idx_lms_sync_records_target_homeroom'
            );

            $table->dropColumn('target_homeroom_id');
        });
    }
};