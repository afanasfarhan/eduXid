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
        // Kita ubah dari Schema::create() menjadi Schema::table()
        // karena tabel 'teachers' sudah ada.
        Schema::table('teachers', function (Blueprint $table) {
            
            // Pengecekan kolom satu per satu agar tidak error "duplicate column"
            if (!Schema::hasColumn('teachers', 'gelar_depan')) {
                $table->string('gelar_depan')->nullable()->after('nama');
            }
            if (!Schema::hasColumn('teachers', 'gelar_belakang')) {
                $table->string('gelar_belakang')->nullable()->after('gelar_depan');
            }
            if (!Schema::hasColumn('teachers', 'tempat_lahir')) {
                $table->string('tempat_lahir')->nullable()->after('jenis_kelamin');
            }
            if (!Schema::hasColumn('teachers', 'tanggal_lahir')) {
                $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            }
            if (!Schema::hasColumn('teachers', 'agama_id_str')) {
                $table->string('agama_id_str')->nullable()->after('tanggal_lahir');
            }
            if (!Schema::hasColumn('teachers', 'jenis_ptk_id_str')) {
                $table->string('jenis_ptk_id_str')->nullable()->after('jenis_ptk');
            }
            if (!Schema::hasColumn('teachers', 'jabatan_ptk_id_str')) {
                $table->string('jabatan_ptk_id_str')->nullable()->after('jenis_ptk_id_str');
            }
            if (!Schema::hasColumn('teachers', 'status_kepegawaian_id_str')) {
                $table->string('status_kepegawaian_id_str')->nullable()->after('status_kepegawaian');
            }
            if (!Schema::hasColumn('teachers', 'bidang_studi_terakhir')) {
                $table->string('bidang_studi_terakhir')->nullable()->after('pendidikan_terakhir');
            }
            if (!Schema::hasColumn('teachers', 'pangkat_golongan_terakhir')) {
                $table->string('pangkat_golongan_terakhir')->nullable()->after('bidang_studi_terakhir');
            }
            if (!Schema::hasColumn('teachers', 'ptk_induk')) {
                $table->string('ptk_induk')->nullable()->after('pangkat_golongan_terakhir');
            }
            if (!Schema::hasColumn('teachers', 'tanggal_surat_tugas')) {
                $table->date('tanggal_surat_tugas')->nullable()->after('ptk_induk');
            }
            if (!Schema::hasColumn('teachers', 'tahun_ajaran_id')) {
                $table->string('tahun_ajaran_id')->nullable()->after('tanggal_surat_tugas');
            }
            
            // Kolom JSON untuk Riwayat (Disimpan sebagai text agar kompatibel dengan SQLite)
            if (!Schema::hasColumn('teachers', 'rwy_pend_formal')) {
                $table->text('rwy_pend_formal')->nullable()->after('tahun_ajaran_id');
            }
            if (!Schema::hasColumn('teachers', 'rwy_kepangkatan')) {
                $table->text('rwy_kepangkatan')->nullable()->after('rwy_pend_formal');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            // Hapus kolom jika di-rollback
            $columnsToDrop = [
                'gelar_depan', 'gelar_belakang', 'tempat_lahir', 'tanggal_lahir', 
                'agama_id_str', 'jenis_ptk_id_str', 'jabatan_ptk_id_str', 
                'status_kepegawaian_id_str', 'bidang_studi_terakhir', 
                'pangkat_golongan_terakhir', 'ptk_induk', 'tanggal_surat_tugas', 
                'tahun_ajaran_id', 'rwy_pend_formal', 'rwy_kepangkatan'
            ];
            
            // Filter kolom yang benar-benar ada sebelum di-drop (mencegah error di SQLite)
            $existingColumns = array_filter($columnsToDrop, function($col) {
                return Schema::hasColumn('teachers', $col);
            });
            
            if (!empty($existingColumns)) {
                $table->dropColumn($existingColumns);
            }
        });
    }
};