<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * =========================================================
     * CREATE SYNC DEVICES TABLE
     * =========================================================
     */
    public function up(): void
    {
        Schema::create('sync_devices', function (Blueprint $table) {

            $table->id();

            /*
             * =====================================================
             * IDENTITAS SEKOLAH
             * =====================================================
             *
             * Satu school_id dapat memiliki banyak device.
             */
            $table->string('school_id', 100);

            /*
             * =====================================================
             * IDENTITAS DEVICE
             * =====================================================
             *
             * UUID unik untuk setiap instalasi eduXid.
             */
            $table->uuid('device_id')->unique();

            /*
             * =====================================================
             * TIPE DEVICE
             * =====================================================
             *
             * teacher = eduXid Guru
             * student = eduXid Siswa
             */
            $table->enum(
                'device_type',
                [
                    'teacher',
                    'student',
                ]
            );

            /*
             * =====================================================
             * USER LOKAL
             * =====================================================
             *
             * Menghubungkan device dengan user eduXid.
             */
            $table->unsignedBigInteger('user_id')->nullable();

            /*
             * =====================================================
             * NAMA DEVICE
             * =====================================================
             */
            $table->string('device_name')->nullable();

            /*
             * =====================================================
             * STATUS
             * =====================================================
             */
            $table->boolean('is_active')->default(true);

            /*
             * =====================================================
             * WAKTU TERAKHIR SYNC
             * =====================================================
             */
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            /*
             * =====================================================
             * INDEX
             * =====================================================
             */
            $table->index(
                [
                    'school_id',
                    'device_type',
                ]
            );

            $table->index('user_id');
        });
    }

    /**
     * =========================================================
     * DROP TABLE
     * =========================================================
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_devices');
    }
};