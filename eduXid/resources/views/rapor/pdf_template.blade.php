<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Rapor {{ $siswa->nama ?? 'Murid' }}</title>
<style>
@page { size: A4; margin: 1.5cm 1.5cm 2cm 1.5cm; }
body { font-family: 'Times New Roman'; font-size: 11px; color: #000; margin: 0; }
.header { text-align: center; margin-bottom: 15px; border-bottom: 3px double #000; padding-bottom: 10px; }
.header h3 { margin: 0; font-size: 16px; text-transform: uppercase; font-weight: bold; }
.header h2 { margin: 5px 0; font-size: 14px; text-transform: uppercase; font-weight: bold; }
.header p { margin: 2px 0; font-size: 10px; }
.identitas { width: 100%; margin-bottom: 15px; }
.identitas td { padding: 2px 5px; vertical-align: top; }
.identitas .label { white-space: nowrap; padding-right: 15px; }
.identitas .titik-dua { width: 10px; }
table.nilai { width: 100%; border-collapse: collapse; margin-top: 5px; table-layout: fixed; }
table.nilai th, table.nilai td { border: 1px solid #000; padding: 5px 8px; text-align: center; vertical-align: top; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; }
table.nilai th { background: #f0f0f0; font-weight: bold; }
.section-title { margin-top: 15px; font-weight: bold; font-size: 12px; margin-bottom: 5px; }
.catatan-wali-page { page-break-before: always; }
.tanda-tangan { page-break-inside: avoid; }
</style>
</head>
<body>
@php
 $appVersion = trim((string) app(\App\Services\Update\CurrentVersion::class)->get());
 $appVersion = preg_replace('/^[vV](?=[0-9])/', '', $appVersion);
 $appVersion = preg_replace('/[^0-9A-Za-z._+\-]/', '', $appVersion) ?: '1.0.0';
 $kelas = $enrollment->kelas ?? '';
 $fase = '-';
if (preg_match('/^10|^X[^I]/i', $kelas)) { $fase = 'E'; }
elseif (preg_match('/^11|^12|^XI|^XII/i', $kelas)) { $fase = 'F'; }
elseif (preg_match('/^[789]/i', $kelas)) { $fase = 'D'; }
elseif (preg_match('/^[56]/i', $kelas)) { $fase = 'C'; }
elseif (preg_match('/^[34]/i', $kelas)) { $fase = 'B'; }
elseif (preg_match('/^[12]/i', $kelas)) { $fase = 'A'; }
@endphp
<div class="header">
<h2>LAPORAN HASIL BELAJAR MURID (RAPOR)</h2>
<h3>{{ $sekolah->nama ?? 'Nama Sekolah' }}</h3>
<p>{{ mb_convert_case(strtolower($sekolah->alamat_jalan ?? '-'), MB_CASE_TITLE, 'UTF-8') }}, {{ $sekolah->kecamatan ?? '-' }}, {{ $sekolah->kabupaten_kota ?? '-' }}</p>
<p>NPSN: {{ $sekolah->npsn ?? '-' }} | Telp: {{ $sekolah->nomor_telepon ?? '-' }} | Email: {{ $sekolah->email ?? '-' }}</p>
</div>
<table class="identitas">
<tr>
<td class="label">Nama Murid</td><td class="titik-dua">:</td>
<td style="padding-right: 20px;"><strong>{{ mb_convert_case(strtolower($siswa->nama ?? '-'), MB_CASE_TITLE, 'UTF-8') }}</strong></td>
<td class="label">Kelas</td><td class="titik-dua">:</td><td>{{ $kelas }}</td>
</tr>
<tr>
<td class="label">NISN / NIS</td><td class="titik-dua">:</td>
<td style="padding-right: 20px;">{{ $siswa->nisn ?? '-' }} / {{ $siswa->nipd ?? '-' }}</td>
<td class="label">Fase</td><td class="titik-dua">:</td><td>{{ $fase }}</td>
</tr>
<tr>
<td class="label">Sekolah</td><td class="titik-dua">:</td>
<td style="padding-right: 20px;">{{ $sekolah->nama ?? '-' }}</td>
<td class="label">Tahun Pelajaran</td><td class="titik-dua">:</td><td>{{ $tahunAjaran ?? '-' }}</td>
</tr>
<tr>
<td class="label">Alamat Sekolah</td><td class="titik-dua">:</td>
<td style="padding-right: 20px;">{{ mb_convert_case(strtolower($sekolah->alamat_jalan ?? '-'), MB_CASE_TITLE, 'UTF-8') }}, {{ mb_convert_case(strtolower($sekolah->kecamatan ?? '-'), MB_CASE_TITLE, 'UTF-8') }}</td>
<td class="label">Semester</td><td class="titik-dua">:</td><td>{{ $semester ?? '-' }}</td>
</tr>
</table>
<div class="section-title">A. Nilai Akademik &amp; Deskripsi Capaian Kompetensi</div>
<table class="nilai">
<thead>
<tr>
<th style="width: 5%;">No</th>
<th style="width: 25%;">Mata Pelajaran</th>
<th style="width: 10%;">Nilai Akhir</th>
<th>Deskripsi Capaian Kompetensi</th>
</tr>
</thead>
<tbody>
@php $no = 1; @endphp
@foreach ($nilais ?? [] as $n)
<tr>
<td>{{ $no++ }}</td>
<td style="text-align: left;">{{ $n->nama_mata_pelajaran ?? '-' }}</td>
<td>{{ $n->nilai_akhir ?? '-' }}</td>
<td style="text-align: left;">{{ $n->deskripsi ?? '-' }}</td>
</tr>
@endforeach
@if(!empty($nilais) && !$nilais->isEmpty())
<tr>
<td colspan="2" style="text-align: left; font-weight: bold;">Nilai Rata-Rata</td>
<td style="font-weight: bold;">{{ isset($rataRataNilai) && $rataRataNilai !== null ? number_format($rataRataNilai, 1, ',', '.') : '-' }}</td>
<td></td>
</tr>
@endif
@if(empty($nilais) || $nilais->isEmpty())
<tr><td colspan="4">Belum ada nilai dimasukkan.</td></tr>
@endif
</tbody>
</table>
<div class="section-title">B. Kokurikuler</div>
<table class="nilai">
<thead>
<tr>
<th style="width: 5%;">No</th>
<th style="width: 20%;">Tema Kokurikuler</th>
<th style="width: 20%;">Judul Kegiatan</th>
<th>Deskripsi Capaian</th>
<th style="width: 15%;">Nilai</th>
</tr>
</thead>
<tbody>
@foreach ($kokurikulers ?? [] as $k)
@php
 $count = max(1, min(5, (int) round($k->rata_sikap ?? 4)));
 $stars = '';
for ($i = 1; $i <= 5; $i++) { $stars .= $i <= $count ? '&#9733;' : '&#9734;'; }
@endphp
<tr>
<td>{{ $loop->iteration }}</td>
<td style="text-align: left;">{{ $k->tema ?? '-' }}</td>
<td style="text-align: left;">{{ $k->nama_kegiatan ?? '-' }}</td>
<td style="text-align: left;">{{ $k->deskripsi ?? '-' }}</td>
<td>
<span style="font-family: 'DejaVu Sans', sans-serif; font-size: 14px;">{!! $stars !!}</span>
<br>
<span style="font-size: 9px;">({{ $predikatText[$count] ?? 'Mulai Berkembang' }})</span>
</td>
</tr>
@endforeach
@if(empty($kokurikulers) || $kokurikulers->isEmpty())
<tr><td colspan="5">Belum ada penilaian kokurikuler.</td></tr>
@endif
</tbody>
</table>
<div class="section-title">C. Ekstrakurikuler</div>
<table class="nilai">
<thead>
<tr>
<th style="width: 5%;">No</th>
<th style="width: 35%;">Kegiatan Ekstrakurikuler</th>
<th>Deskripsi Capaian</th>
<th style="width: 15%;">Nilai</th>
</tr>
</thead>
<tbody>
@foreach ($ekskul_grades ?? [] as $e)
@php
 $count = max(1, min(5, (int) round($e->rata_sikap ?? 4)));
 $stars = '';
for ($i = 1; $i <= 5; $i++) { $stars .= $i <= $count ? '&#9733;' : '&#9734;'; }
@endphp
<tr>
<td>{{ $loop->iteration }}</td>
<td style="text-align: left;">{{ $e->nama_ekskul ?? '-' }}</td>
<td style="text-align: left;">{{ $e->deskripsi ?? '-' }}</td>
<td>
<span style="font-family: 'DejaVu Sans', sans-serif; font-size: 14px;">{!! $stars !!}</span>
<br>
<span style="font-size: 9px;">({{ $predikatText[$count] ?? 'Mulai Berkembang' }})</span>
</td>
</tr>
@endforeach
@if(empty($ekskul_grades) || $ekskul_grades->isEmpty())
<tr><td colspan="4">Belum ada data ekstrakurikuler.</td></tr>
@endif
</tbody>
</table>
<div class="section-title">D. Ketidakhadiran</div>
<table class="nilai">
<thead>
<tr>
<th style="width: 33%;">Sakit</th>
<th style="width: 33%;">Izin</th>
<th style="width: 34%;">Tanpa Keterangan</th>
</tr>
</thead>
<tbody>
<tr>
<td>{{ $enrollment->rapor_sakit ?? 0 }} Hari</td>
<td>{{ $enrollment->rapor_izin ?? 0 }} Hari</td>
<td>{{ $enrollment->rapor_alpa ?? 0 }} Hari</td>
</tr>
</tbody>
</table>
<div class="catatan-wali-page">
<div class="section-title">E. Catatan Wali Kelas</div>
<table class="nilai">
<tbody>
<tr>
<td style="height: 140px; vertical-align: top; text-align: left;">
{{ $enrollment->catatan_wali ?? '-' }}
<br><br>
<p style="font-weight: bold; margin-bottom: 2px;">Rata-rata Penilaian Karakter dari Guru Mapel:</p>
<table style="width: 100%; border-collapse: collapse; margin-top: 5px;">
@php
 $dimensi = [1 => 'Keimanan', 2 => 'Kewargaan', 3 => 'Penalaran', 4 => 'Kreativitas', 5 => 'Kolaborasi', 6 => 'Kemandirian', 7 => 'Kesehatan', 8 => 'Komunikasi'];
 $dimensiChunks = array_chunk($dimensi, 2, true);
@endphp
@foreach($dimensiChunks as $chunk)
<tr>
@foreach($chunk as $i => $nama)
@php
 $nilaiDimensi = optional($sikap)->{'avg_d'.$i} ?? 3;
 $nilaiDimensi = is_numeric($nilaiDimensi) ? (int) round($nilaiDimensi) : 3;
 $nilaiDimensi = max(1, min(5, $nilaiDimensi));
@endphp
<td style="width: 50%; border: 1px solid #e5e7eb; padding: 4px 8px; vertical-align: middle;">
<span style="font-size: 10px; font-weight: bold;">{{ $i }}. {{ $nama }}</span>
<br>
<span style="font-family: 'DejaVu Sans', sans-serif; font-size: 14px; letter-spacing: 1px;">
@for($j = 1; $j <= 5; $j++)
{{ $j <= $nilaiDimensi ? '★' : '☆' }}
@endfor
</span>

{{-- EDUXID_STATUS_AKHIR_SEMESTER_RAPOR_V1 --}}
@php
    $eduxidSemesterRapor = (string) (
        $effectiveSemester
        ?? ($rombel->semester_id ?? null)
        ?? session('semester_aktif', '')
    );

    $eduxidStudentIdStatus =
        $siswa->id
        ?? ($student->id ?? null)
        ?? ($enrollment->student_id ?? null);

    $eduxidHomeroomIdStatus =
        $rombel->id
        ?? ($homeroom->id ?? null)
        ?? ($enrollment->homeroom_id ?? null);

    $eduxidStatusAkhir = null;
    $eduxidStatusAkhirLabel = null;
    $eduxidStatusAkhirHeading = null;

    if (
        \App\Services\PromotionStatusService::isEvenSemester(
            $eduxidSemesterRapor
        )
        && $eduxidStudentIdStatus
        && $eduxidHomeroomIdStatus
    ) {
        $eduxidStatusAkhir = \Illuminate\Support\Facades\DB::table('enrollments')
            ->where('student_id', $eduxidStudentIdStatus)
            ->where('homeroom_id', $eduxidHomeroomIdStatus)
            ->value('status_akhir_semester');

        $eduxidStatusAkhirLabel =
            \App\Services\PromotionStatusService::label(
                $eduxidStatusAkhir
            );

        if ($eduxidStatusAkhirLabel) {
            $eduxidHomeroomStatus =
                \Illuminate\Support\Facades\DB::table('homerooms')
                    ->where('id', $eduxidHomeroomIdStatus)
                    ->first();

            $eduxidStatusAkhirHeading =
                \App\Services\PromotionStatusService::reportHeading(
                    $eduxidHomeroomStatus
                );
        }
    }
@endphp

@if($eduxidStatusAkhirLabel)
    <div style="margin-top: 7px; padding-top: 5px; border-top: 1px solid #999;">
        <strong>{{ $eduxidStatusAkhirHeading }}:</strong>
        {{ $eduxidStatusAkhirLabel }}
    </div>
@endif
</td>
@endforeach
</tr>
@endforeach
</table>
<br>
<p style="font-size: 11px;">Predikat rata-rata: <strong>{{ $predikatText[$rataSikapVal] ?? 'Mulai Berkembang' }}</strong></p>
</td>
</tr>
</tbody>
</table>
<div class="tanda-tangan" style="margin-top: 40px; width: 100%;">
<div style="width: 100%; display: table;">
<div style="display: table-cell; width: 50%; text-align: center;">
<p>Mengetahui,<br>Orang Tua / Wali</p>
<div style="margin-top: 40px; font-weight: bold; text-decoration: underline;">______________&nbsp;</div>
</div>
<div style="display: table-cell; width: 50%; text-align: center;">
<p>{{ $sekolah->kabupaten_kota ?? 'Kota' }}, {{ $tanggal_cetak ?? '-' }}<br>Wali Kelas</p>
<div style="margin-top: 40px; font-weight: bold; text-decoration: underline;">{{ $enrollment->wali_kelas ?: '-' }}</div>
<p style="font-size: 10px; margin-top: 2px;">NIP. {{ $enrollment->wali_nip ?: '-' }}</p>
</div>
</div>
<div style="width: 100%; text-align: center; margin-top: 10px;">
<p>Kepala Sekolah</p>
<div style="margin-top: 40px; font-weight: bold; text-decoration: underline;">{{ $kepala_sekolah ?? '-' }}</div>
<p style="font-size: 10px; margin-top: 2px;">NIP. {{ $kepala_nip ?? '-' }}</p>
</div>
</div>
</div>
<script type="text/php">
if (isset($pdf)) {
 $font = $fontMetrics->getFont('Times New Roman', 'normal');
 $color = array(0.4, 0.4, 0.4);
 $y = 815;
 $pdf->page_text(42, $y, "eduXid v{{ $appVersion }}", $font, 9, $color);
 $pdf->page_text(440, $y, "Halaman {PAGE_NUM} dari {PAGE_COUNT}", $font, 9, $color);
}
</script>
</body>
</html>