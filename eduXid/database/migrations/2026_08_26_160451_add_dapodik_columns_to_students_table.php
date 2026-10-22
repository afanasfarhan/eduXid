<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'nik')) {
                $table->string('nik')->nullable()->after('nisn');
            }
            if (!Schema::hasColumn('students', 'tanggal_masuk_sekolah')) {
                $table->date('tanggal_masuk_sekolah')->nullable()->after('nik');
            }
            if (!Schema::hasColumn('students', 'sekolah_asal')) {
                $table->string('sekolah_asal')->nullable()->after('tanggal_masuk_sekolah');
            }
            if (!Schema::hasColumn('students', 'jenis_pendaftaran')) {
                $table->string('jenis_pendaftaran')->nullable()->after('sekolah_asal');
            }
            if (!Schema::hasColumn('students', 'nomor_telepon_rumah')) {
                $table->string('nomor_telepon_rumah')->nullable()->after('alamat_jalan');
            }
            if (!Schema::hasColumn('students', 'nomor_telepon_seluler')) {
                $table->string('nomor_telepon_seluler')->nullable()->after('nomor_telepon_rumah');
            }
            if (!Schema::hasColumn('students', 'email')) {
                $table->string('email')->nullable()->after('nomor_telepon_seluler');
            }
            if (!Schema::hasColumn('students', 'pekerjaan_ayah')) {
                $table->string('pekerjaan_ayah')->nullable()->after('nama_ayah');
            }
            if (!Schema::hasColumn('students', 'pekerjaan_ibu')) {
                $table->string('pekerjaan_ibu')->nullable()->after('nama_ibu');
            }
            if (!Schema::hasColumn('students', 'nama_wali')) {
                $table->string('nama_wali')->nullable()->after('pekerjaan_ibu');
            }
            if (!Schema::hasColumn('students', 'pekerjaan_wali')) {
                $table->string('pekerjaan_wali')->nullable()->after('nama_wali');
            }
            if (!Schema::hasColumn('students', 'anak_keberapa')) {
                $table->string('anak_keberapa')->nullable()->after('pekerjaan_wali');
            }
            if (!Schema::hasColumn('students', 'tinggi_badan')) {
                $table->string('tinggi_badan')->nullable()->after('anak_keberapa');
            }
            if (!Schema::hasColumn('students', 'berat_badan')) {
                $table->string('berat_badan')->nullable()->after('tinggi_badan');
            }
            if (!Schema::hasColumn('students', 'kebutuhan_khusus')) {
                $table->string('kebutuhan_khusus')->nullable()->after('berat_badan');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $columnsToDrop = [
                'nik', 'tanggal_masuk_sekolah', 'sekolah_asal', 'jenis_pendaftaran',
                'nomor_telepon_rumah', 'nomor_telepon_seluler', 'email',
                'pekerjaan_ayah', 'pekerjaan_ibu', 'nama_wali', 'pekerjaan_wali',
                'anak_keberapa', 'tinggi_badan', 'berat_badan', 'kebutuhan_khusus'
            ];
            
            $existingColumns = array_filter($columnsToDrop, function($col) {
                return Schema::hasColumn('students', $col);
            });
            
            if (!empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};