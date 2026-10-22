<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Pilih worksheet pertama saja sebagai data soal. Workbook dapat
 * menyertakan sheet Panduan TKA/metadata; itu bukan baris bank soal.
 * Import soal dan gambar tetap dilaksanakan oleh SoalImport.
 */
final class SoalWorkbookImport implements WithMultipleSheets
{
    public function __construct(private SoalImport $import)
    {
    }

    public function sheets(): array
    {
        return [0 => $this->import];
    }
}
