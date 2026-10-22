<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Exception;

class GuruImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        // Cek jika file kosong
        if ($rows->isEmpty()) {
            throw new Exception("File Excel kosong atau tidak memiliki data.");
        }

        // Validasi Header Ketat
        $expectedHeader = ['nik', 'nama_lengkap', 'gelar_depan', 'gelar_belakang', 'jenis_kelamin_lp', 'nuptk', 'nip', 'jenis_gtk', 'status_kepegawaian', 'pendidikan_terakhir'];
        $actualHeader = array_keys($rows->first()->toArray());
        
        if (array_diff($expectedHeader, $actualHeader) || array_diff($actualHeader, $expectedHeader)) {
            throw new Exception("Format Excel Tidak Valid. Header kolom tidak sesuai dengan Template yang disediakan.");
        }

        $rules = [
            'nik' => 'required',
            'nama_lengkap' => 'required',
            'jenis_kelamin_lp' => 'required|in:L,P',
        ];

        foreach ($rows as $index => $row) {
            $validator = Validator::make($row->toArray(), $rules);

            if ($validator->fails()) {
                $errors = $validator->errors()->all();
                throw new Exception("Error pada baris " . ($index + 2) . ": " . implode(", ", $errors));
            }

            // Konversi Tanggal dari format Excel (Serial Number) ke YYYY-MM-DD (Jika ada penambahan kolom tanggal di masa depan)
            $tanggalLahir = $row['tanggal_lahir_yyyy_mm_dd'] ?? null; // Contoh jika ada kolom tanggal
            if (is_numeric($tanggalLahir)) {
                $tanggalLahir = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($tanggalLahir)->format('Y-m-d');
            } elseif (!empty($tanggalLahir)) {
                $tanggalLahir = date('Y-m-d', strtotime($tanggalLahir));
            }

            DB::table('teachers')->updateOrInsert(
                ['nik' => $row['nik']],
                [
                    'dapodik_ptk_id' => 'excel-' . Str::uuid(),
                    'nama' => $row['nama_lengkap'],
                    'gelar_depan' => $row['gelar_depan'] ?? null,
                    'gelar_belakang' => $row['gelar_belakang'] ?? null,
                    'jenis_kelamin' => $row['jenis_kelamin_lp'],
                    'nuptk' => $row['nuptk'] ?? null,
                    'nip' => $row['nip'] ?? null,
                    'jenis_ptk' => $row['jenis_gtk'] ?? null,
                    'status_kepegawaian' => $row['status_kepegawaian'] ?? null,
                    'pendidikan_terakhir' => $row['pendidikan_terakhir'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}