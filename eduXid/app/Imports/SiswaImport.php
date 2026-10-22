<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Exception;

class SiswaImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        // Cek jika file kosong
        if ($rows->isEmpty()) {
            throw new Exception("File Excel kosong atau tidak memiliki data.");
        }

        // Validasi Header Ketat (Sesuai urutan Template baru)
        $expectedHeader = ['nama_kelas', 'nipd', 'nama_lengkap', 'nisn', 'jenis_kelamin_lp', 'tempat_lahir', 'tanggal_lahir_yyyy_mm_dd', 'agama', 'alamat_jalan', 'nama_ayah', 'nama_ibu'];
        $actualHeader = array_keys($rows->first()->toArray());
        
        if (array_diff($expectedHeader, $actualHeader) || array_diff($actualHeader, $expectedHeader)) {
            throw new Exception("Format Excel Tidak Valid. Header kolom tidak sesuai dengan Template yang disediakan.");
        }

        $rules = [
            'nama_kelas' => 'required',
            'nisn' => 'required',
            'nama_lengkap' => 'required',
            'jenis_kelamin_lp' => 'required|in:L,P',
        ];

        foreach ($rows as $index => $row) {
            $validator = Validator::make($row->toArray(), $rules);

            if ($validator->fails()) {
                $errors = $validator->errors()->all();
                throw new Exception("Error pada baris " . ($index + 2) . ": " . implode(", ", $errors));
            }

            // 1. Cek apakah Kelas tersebut ada di Database
            $homeroom = DB::table('homerooms')->where('nama', $row['nama_kelas'])->first();
            if (!$homeroom) {
                throw new Exception("Error pada baris " . ($index + 2) . ": Nama Kelas '{$row['nama_kelas']}' tidak ditemukan di Database. Harap buat Kelas tersebut terlebih dahulu via menu Data Kelas atau Import Excel Kelas.");
            }

            // 2. Konversi Tanggal Lahir dari format Excel (Serial Number) ke YYYY-MM-DD
            $tanggalLahir = $row['tanggal_lahir_yyyy_mm_dd'] ?? null;
            if (is_numeric($tanggalLahir)) {
                $tanggalLahir = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tanggalLahir)->format('Y-m-d');
            } elseif (!empty($tanggalLahir)) {
                $tanggalLahir = date('Y-m-d', strtotime($tanggalLahir));
            }

            // 3. Simpan/Update Data Siswa
            DB::table('students')->updateOrInsert(
                ['nisn' => $row['nisn']],
                [
                    'dapodik_peserta_didik_id' => 'excel-' . Str::uuid(),
                    'nipd' => $row['nipd'] ?? null,
                    'nama' => $row['nama_lengkap'],
                    'jenis_kelamin' => $row['jenis_kelamin_lp'],
                    'tempat_lahir' => $row['tempat_lahir'] ?? null,
                    'tanggal_lahir' => $tanggalLahir,
                    'agama' => $row['agama'] ?? null,
                    'alamat_jalan' => $row['alamat_jalan'] ?? null,
                    'nama_ayah' => $row['nama_ayah'] ?? null,
                    'nama_ibu' => $row['nama_ibu'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // 4. Ambil ID Siswa yang baru saja disimpan
            $student = DB::table('students')->where('nisn', $row['nisn'])->first();

            // 5. Daftarkan Siswa ke Kelas tersebut (Tabel Enrollments)
            DB::table('enrollments')->updateOrInsert(
                ['student_id' => $student->id, 'homeroom_id' => $homeroom->id],
                [
                    'dapodik_anggota_rombel_id' => 'excel-' . Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}