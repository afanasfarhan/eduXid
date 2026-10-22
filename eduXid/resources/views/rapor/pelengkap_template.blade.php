<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pelengkap Rapor - {{ $siswa->nama ?? '' }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 20mm 20mm 20mm 30mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10pt;
            color: #111;
            line-height: 1.28;
        }

        .page {
            position: relative;
        }

        .page-break {
            page-break-before: always;
        }

        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .uppercase { text-transform: uppercase; }
        .nowrap { white-space: nowrap; }

        .cover {
            text-align: center;
            padding-top: 12mm;
        }

        .cover-title {
            font-size: 20pt;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin: 0 0 10mm;
        }

        .cover-logo {
            display: block;
            width: 40mm;
            height: auto;
            max-height: 42mm;
            margin: 0 auto 9mm;
        }

        .cover-logo-placeholder {
            width: 40mm;
            height: 40mm;
            margin: 0 auto 9mm;
            border: 0.3mm solid #aaa;
            text-align: center;
            line-height: 40mm;
            font-size: 8pt;
            color: #777;
        }

        .cover-school-name {
            font-size: 16pt;
            font-weight: 700;
            text-transform: uppercase;
            margin: 0 0 2.5mm;
        }

        .cover-npsn {
            font-size: 11.5pt;
            font-weight: 700;
            margin: 0;
        }

        .cover-student {
            margin-top: 28mm;
        }

        .cover-label {
            font-size: 11pt;
            margin-bottom: 3mm;
            font-weight: 700;
        }

        .cover-value {
            display: inline-block;
            width: 120mm;
            padding: 2.4mm 4mm;
            border: 0.3mm solid #222;
            font-size: 13pt;
            font-weight: 700;
        }

        .cover-value + .cover-label {
            margin-top: 10mm;
        }

        .page-title {
            text-align: center;
            font-size: 17pt;
            font-weight: 700;
            margin: 8mm 0 3mm;
        }

        .page-subtitle {
            text-align: center;
            font-size: 13pt;
            font-weight: 700;
            margin-bottom: 16mm;
        }

        table.identity {
            width: 100%;
            border-collapse: collapse;
        }

        table.identity td {
            padding: 2.2mm 1mm;
            vertical-align: top;
        }

        table.identity td.label {
            width: 43mm;
        }

        table.identity td.sep {
            width: 5mm;
            text-align: center;
        }

        .student-page {
            font-size: 9.2pt;
            line-height: 1.18;
        }

        .student-title {
            font-size: 13pt;
            text-align: center;
            font-weight: 700;
            margin: 0 0 4mm;
        }

        table.student-info {
            width: 100%;
            border-collapse: collapse;
        }

        table.student-info td {
            vertical-align: top;
            padding: 0.58mm 0.6mm;
        }

        table.student-info td.no {
            width: 7mm;
            text-align: right;
            padding-right: 2mm;
        }

        table.student-info td.label {
            width: 58mm;
        }

        table.student-info td.sep {
            width: 5mm;
            text-align: center;
        }

        .sub-row {
            padding-left: 8mm !important;
        }

        .bio-footer {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4mm;
            page-break-inside: avoid;
        }

        .bio-footer td {
            vertical-align: top;
            padding: 0;
        }

        .bio-photo-cell {
            width: 58mm;
            text-align: center;
            padding-top: 2mm !important;
        }

        .bio-photo-frame {
            display: inline-block;
            width: 24mm;
            height: 32mm;
            border: 0.35mm solid #444;
            text-align: center;
            overflow: hidden;
            background: #fff;
        }

        .bio-photo-frame img {
            display: block;
            object-fit: contain;
            margin: 0 auto;
            max-width: 24mm;
            max-height: 32mm;
        }

        .bio-photo-placeholder {
            display: inline-block;
            width: 24mm;
            height: 32mm;
            border: 0.35mm solid #777;
            background: #fff;
        }

        .signature {
            width: 72mm;
            margin-left: auto;
            text-align: left;
            font-size: 9.2pt;
            line-height: 1.25;
        }

        .signature-space {
            height: 14mm;
        }

        .signature-name {
            font-weight: 700;
            text-decoration: underline;
        }

        .transfer-page {
            font-size: 9pt;
            line-height: 1.22;
        }

        .line-value {
            display: inline-block;
            border-bottom: 1px dotted #111;
            min-width: 95mm;
            padding: 0 2mm 1mm;
        }

        table.transfer tr.transfer-document-title th {
            border: none !important;
            background: transparent !important;
            text-align: center;
            font-size: 13pt;
            font-weight: 700;
            padding: 0 0 7mm !important;
        }

        table.transfer tr.transfer-student-name td {
            border: none !important;
            padding: 0 0 6mm !important;
            font-size: 9.3pt;
        }

        table.transfer {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: avoid;
        }

        table.transfer th,
        table.transfer td {
            border: 1px solid #222;
            padding: 1.4mm;
            vertical-align: top;
        }

        table.transfer th {
            text-align: center;
            font-weight: 700;
            background: #f8f8f8;
            line-height: 1.25;
        }

        .transfer-out-row {
            height: 53mm;
            page-break-inside: avoid;
        }

        .transfer-sign {
            line-height: 1.32;
            padding: 2.2mm 2.6mm !important;
        }

        .dotted {
            display: block;
            border-bottom: 1px dotted #111;
            height: 5.5mm;
            margin-bottom: 1mm;
        }

        .transfer-in-row {
            height: 55mm;
            page-break-inside: avoid;
        }

        table.inner-form {
            width: 100%;
            border-collapse: collapse;
        }

        table.inner-form td {
            border: none;
            padding: 1.2mm 0.5mm;
            vertical-align: top;
        }

        .blank-line {
            display: inline-block;
            min-width: 42mm;
            border-bottom: 1px solid #333;
            min-height: 5mm;
        }


        .transfer-in-row > td {
            padding: 0 !important;
        }

        .transfer-no-cell,
        .transfer-label-cell,
        .transfer-value-cell,
        .transfer-in-sign {
            height: 55mm;
        }

        .transfer-entry-line {
            height: 7.2mm;
            line-height: 7.2mm;
            white-space: nowrap;
        }

        .transfer-no-cell {
            text-align: center;
            padding-top: 2mm !important;
        }

        .transfer-label-cell,
        .transfer-value-cell {
            padding: 2mm 2mm 1.5mm !important;
        }

        .transfer-value-line {
            height: 7.2mm;
            border-bottom: 0.25mm solid #555;
        }

        .transfer-value-line.blank {
            border-bottom: none;
        }

        .transfer-in-sign {
            padding: 3mm 2.5mm !important;
            line-height: 1.35;
        }

        .transfer-sign-space {
            height: 17mm;
        }

        .small { font-size: 9pt; }
    </style>
</head>
<body>
@php
    $nama = trim((string) ($siswa->nama ?? ''));
    $nisn = trim((string) ($siswa->nisn ?? ''));
    $nis = trim((string) ($siswa->nipd ?? ''));

    $alamatSekolah = trim((string) ($sekolah->alamat_jalan ?? ''));
    $kelurahanSekolah = trim((string) ($sekolah->desa_kelurahan ?? ''));

    /*
     * Field berikut tidak tersedia pada master students eduXid saat ini.
     * Jangan menebak dari field lain; kosongkan sampai Dapodik menyediakan
     * field yang benar dan skema eduXid menampungnya.
     */
    $alamatOrtu = '';
    $teleponOrtu = '';
    $alamatWali = '';
    $teleponWali = '';
    $statusKeluarga = '';

    $kepsekNama = trim((string) ($kepalaSekolah->nama_lengkap ?? ''));
    $kepsekNip = trim((string) ($kepalaSekolah->nip ?? ''));

    $tahunMasuk = '';
    if (!empty($siswa->tanggal_masuk_sekolah)) {
        try {
            $tahunMasukInt = (int) date('Y', strtotime((string) $siswa->tanggal_masuk_sekolah));
            if ($tahunMasukInt > 0) {
                $tahunMasuk = $tahunMasukInt . '/' . ($tahunMasukInt + 1);
            }
        } catch (\Throwable) {
            $tahunMasuk = '';
        }
    }
    $logoTutWuriDataUri = '';
    try {
        $logoPath = public_path('img/tut-wuri-handayani.png');
        if (is_file($logoPath) && is_readable($logoPath)) {
            $logoTutWuriDataUri = 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath));
        }
    } catch (\Throwable) {
        $logoTutWuriDataUri = '';
    }
@endphp

{{-- ============================================================
     HALAMAN 1 — SAMPUL
============================================================ --}}
<div class="page cover">
    <div class="cover-title">LAPORAN HASIL BELAJAR MURID</div>

    @if(!empty($logoTutWuriDataUri))
        <img src="{{ $logoTutWuriDataUri }}" alt="Tut Wuri Handayani" class="cover-logo">
    @else
        <div class="cover-logo-placeholder">LOGO</div>
    @endif

    <div class="cover-school-name">{{ $sekolah->nama ?? '' }}</div>
    <div class="cover-npsn">NPSN : {{ $sekolah->npsn ?? '-' }}</div>

    <div class="cover-student">
        <div class="cover-label">Nama Murid</div>
        <div class="cover-value uppercase">{{ $nama ?: '................................................' }}</div>

        <div class="cover-label">NISN / NIS</div>
        <div class="cover-value">{{ $nisn ?: '-' }} / {{ $nis ?: '-' }}</div>
    </div>
</div>

{{-- ============================================================
     HALAMAN 2 — IDENTITAS SEKOLAH
============================================================ --}}
<div class="page page-break">
    <div class="page-title">RAPOR</div>
    <div class="page-subtitle">{{ $bentukPendidikan }}</div>

    <table class="identity">
        <tr><td class="label">Nama Sekolah</td><td class="sep">:</td><td class="uppercase">{{ $sekolah->nama ?? '' }}</td></tr>
        <tr><td class="label">NPSN</td><td class="sep">:</td><td>{{ $sekolah->npsn ?? '' }}</td></tr>
        <tr><td class="label">NIS/NSS/NDS</td><td class="sep">:</td><td>{{ $sekolah->nss ?? '' }}</td></tr>
        <tr><td class="label">Alamat Sekolah</td><td class="sep">:</td><td class="uppercase">{{ $alamatSekolah }}</td></tr>
        <tr><td class="label">Kelurahan / Desa</td><td class="sep">:</td><td>{{ $kelurahanSekolah }}</td></tr>
        <tr><td class="label">Kecamatan</td><td class="sep">:</td><td>{{ $sekolah->kecamatan ?? '' }}</td></tr>
        <tr><td class="label">Kota/Kabupaten</td><td class="sep">:</td><td>{{ $sekolah->kabupaten_kota ?? '' }}</td></tr>
        <tr><td class="label">Provinsi</td><td class="sep">:</td><td>{{ $sekolah->provinsi ?? '' }}</td></tr>
        <tr><td class="label">Website</td><td class="sep">:</td><td>{{ $sekolah->website ?? '' }}</td></tr>
        <tr><td class="label">E-mail</td><td class="sep">:</td><td>{{ $sekolah->email ?? '' }}</td></tr>
    </table>
</div>

{{-- ============================================================
     HALAMAN 3 — IDENTITAS PESERTA DIDIK
============================================================ --}}
<div class="page page-break student-page">
    <div class="student-title">IDENTITAS MURID</div>

    <table class="student-info">
        <tr><td class="no">1.</td><td class="label">Nama Lengkap Murid</td><td class="sep">:</td><td class="uppercase">{{ $nama }}</td></tr>
        <tr><td class="no">2.</td><td class="label">Nomor Induk/NISN</td><td class="sep">:</td><td>{{ $nis ?: '-' }} / {{ $nisn ?: '-' }}</td></tr>
        <tr><td class="no">3.</td><td class="label">Tempat, Tanggal Lahir</td><td class="sep">:</td><td>{{ mb_strtoupper((string) ($siswa->tempat_lahir ?? ''), 'UTF-8') }}{{ !empty($siswa->tempat_lahir) && $tanggalLahir ? ', ' : '' }}{{ $tanggalLahir }}</td></tr>
        <tr><td class="no">4.</td><td class="label">Jenis Kelamin</td><td class="sep">:</td><td>{{ $jenisKelamin }}</td></tr>
        <tr><td class="no">5.</td><td class="label">Agama</td><td class="sep">:</td><td>{{ $siswa->agama ?? '' }}</td></tr>
        <tr><td class="no">6.</td><td class="label">Status dalam Keluarga</td><td class="sep">:</td><td>{{ $statusKeluarga }}</td></tr>
        <tr><td class="no">7.</td><td class="label">Anak ke</td><td class="sep">:</td><td>{{ $siswa->anak_keberapa ?? '' }}</td></tr>
        <tr><td class="no">8.</td><td class="label">Alamat Murid</td><td class="sep">:</td><td>{{ $siswa->alamat_jalan ?? '' }}</td></tr>
        <tr><td class="no">9.</td><td class="label">Nomor Telepon Rumah</td><td class="sep">:</td><td>{{ $siswa->nomor_telepon_rumah ?? '' }}</td></tr>
        <tr><td class="no">10.</td><td class="label">Sekolah Asal (SMP/MTs)</td><td class="sep">:</td><td class="uppercase">{{ $siswa->sekolah_asal ?? '' }}</td></tr>
        <tr><td class="no">11.</td><td class="label">Diterima di sekolah ini</td><td class="sep">:</td><td></td></tr>
        <tr><td></td><td class="label sub-row">Di kelas</td><td class="sep">:</td><td>{{ $kelasMasuk }}</td></tr>
        <tr><td></td><td class="label sub-row">Pada tanggal</td><td class="sep">:</td><td>{{ $tanggalMasuk }}</td></tr>
        <tr><td class="no">12.</td><td class="label">Nama Orang Tua</td><td class="sep"></td><td></td></tr>
        <tr><td></td><td class="label sub-row">a. Ayah</td><td class="sep">:</td><td class="uppercase">{{ $siswa->nama_ayah ?? '' }}</td></tr>
        <tr><td></td><td class="label sub-row">b. Ibu</td><td class="sep">:</td><td class="uppercase">{{ $siswa->nama_ibu ?? '' }}</td></tr>
        <tr><td class="no">13.</td><td class="label">Alamat Orang Tua</td><td class="sep">:</td><td>{{ $alamatOrtu }}</td></tr>
        <tr><td></td><td class="label sub-row">Nomor Telepon Rumah</td><td class="sep">:</td><td>{{ $teleponOrtu }}</td></tr>
        <tr><td class="no">14.</td><td class="label">Pekerjaan Orang Tua</td><td class="sep"></td><td></td></tr>
        <tr><td></td><td class="label sub-row">a. Ayah</td><td class="sep">:</td><td>{{ $siswa->pekerjaan_ayah ?? '' }}</td></tr>
        <tr><td></td><td class="label sub-row">b. Ibu</td><td class="sep">:</td><td>{{ $siswa->pekerjaan_ibu ?? '' }}</td></tr>
        <tr><td class="no">15.</td><td class="label">Nama Wali Murid</td><td class="sep">:</td><td class="uppercase">{{ $siswa->nama_wali ?? '' }}</td></tr>
        <tr><td class="no">16.</td><td class="label">Alamat Wali Murid</td><td class="sep">:</td><td>{{ $alamatWali }}</td></tr>
        <tr><td></td><td class="label sub-row">Nomor Telepon Rumah</td><td class="sep">:</td><td>{{ $teleponWali }}</td></tr>
        <tr><td class="no">17.</td><td class="label">Pekerjaan Wali Murid</td><td class="sep">:</td><td>{{ $siswa->pekerjaan_wali ?? '' }}</td></tr>
    </table>

    <table class="bio-footer">
        <tr>
            <td class="bio-photo-cell">
                @if(!empty($fotoDataUri))
                    @php
                        // DomPDF tidak selalu menghormati object-fit. Hitung ukuran
                        // foto proporsional agar seluruh foto tetap terlihat.
                        $fotoWidthMm = 24.0;
                        $fotoHeightMm = 32.0;
                        $fotoTopMm = 0.0;
                        try {
                            $fotoEncoded = substr((string) $fotoDataUri, strpos((string) $fotoDataUri, ',') + 1);
                            $fotoBinary = base64_decode($fotoEncoded, true);
                            $fotoDimensions = is_string($fotoBinary) ? @getimagesizefromstring($fotoBinary) : false;
                            if (is_array($fotoDimensions) && ($fotoDimensions[0] ?? 0) > 0 && ($fotoDimensions[1] ?? 0) > 0) {
                                $fotoRatio = min(24 / $fotoDimensions[0], 32 / $fotoDimensions[1]);
                                $fotoWidthMm = round($fotoDimensions[0] * $fotoRatio, 3);
                                $fotoHeightMm = round($fotoDimensions[1] * $fotoRatio, 3);
                                $fotoTopMm = round((32 - $fotoHeightMm) / 2, 3);
                            }
                        } catch (\Throwable $ignorePhotoDimensionError) {
                            // Untuk data URI tidak dikenal, tetap gunakan object-fit CSS.
                        }
                    @endphp
                    <div class="bio-photo-frame" style="padding-top: {{ $fotoTopMm }}mm; box-sizing: border-box;">
                        <img src="{{ $fotoDataUri }}" alt="Foto {{ $nama }}" style="width: {{ $fotoWidthMm }}mm; height: {{ $fotoHeightMm }}mm;">
                    </div>
                @else
                    <div class="bio-photo-placeholder"></div>
                @endif
            </td>
            <td>
                <div class="signature">
                    <div>{{ $tempatCetak }}{{ $tempatCetak !== '' && $tanggalCetak !== '' ? ', ' : '' }}{{ $tanggalCetak }}</div>
                    <div>Kepala Sekolah</div>
                    <div class="signature-space"></div>
                    <div class="signature-name">{{ $kepsekNama ?: '................................................' }}</div>
                    <div>NIP. {{ $kepsekNip }}</div>
                </div>
            </td>
        </tr>
    </table>
</div>

{{-- ============================================================
     HALAMAN 4 — KETERANGAN PINDAH SEKOLAH / KELUAR
============================================================ --}}
<div class="page page-break transfer-page">
    <table class="transfer">
        <colgroup>
            <col style="width: 15%;">
            <col style="width: 18%;">
            <col style="width: 30%;">
            <col style="width: 37%;">
        </colgroup>
        <thead>
            <tr class="transfer-document-title">
                <th colspan="4">KETERANGAN PINDAH SEKOLAH</th>
            </tr>
            <tr class="transfer-student-name">
                <td colspan="4">
                    Nama Murid&nbsp;&nbsp;&nbsp;:&nbsp;&nbsp;
                    <span class="line-value uppercase">{{ $nama }}</span>
                </td>
            </tr>
            <tr><th colspan="4">KELUAR</th></tr>
            <tr>
                <th>Tanggal</th>
                <th>Kelas yang<br>ditinggalkan</th>
                <th>Sebab-sebab Keluar atau<br>Atas Permintaan<br>(Tertulis)</th>
                <th>Tanda Tangan Kepala Sekolah,<br>Stempel Sekolah, dan Tanda<br>Tangan Orang Tua/Wali</th>
            </tr>
        </thead>
        <tbody>
            @for($i = 0; $i < 3; $i++)
                <tr class="transfer-out-row">
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="transfer-sign">
                        <span class="dotted"></span>
                        Kepala Sekolah,<br>
                        <span style="display:block;height:10mm;"></span>
                        <span class="dotted"></span>
                        NIP.<br>
                        <span style="display:block;height:4mm;"></span>
                        Orang Tua/Wali,<br>
                        <span style="display:block;height:9mm;"></span>
                        <span class="dotted"></span>
                    </td>
                </tr>
            @endfor
        </tbody>
    </table>
</div>

{{-- ============================================================
     HALAMAN 5 — KETERANGAN PINDAH SEKOLAH / MASUK
============================================================ --}}
<div class="page page-break transfer-page">
    <table class="transfer">
        <colgroup>
            <col style="width: 7%;">
            <col style="width: 31%;">
            <col style="width: 31%;">
            <col style="width: 31%;">
        </colgroup>
        <thead>
            <tr class="transfer-document-title">
                <th colspan="4">KETERANGAN PINDAH SEKOLAH</th>
            </tr>
            <tr class="transfer-student-name">
                <td colspan="4">
                    Nama Murid&nbsp;&nbsp;&nbsp;:&nbsp;&nbsp;
                    <span class="line-value uppercase">{{ $nama }}</span>
                </td>
            </tr>
            <tr>
                <th>NO</th>
                <th colspan="3">MASUK</th>
            </tr>
        </thead>
        <tbody>
            @for($i = 0; $i < 3; $i++)
                <tr class="transfer-in-row">
                    <td class="transfer-no-cell">
                        <div class="transfer-entry-line">1.</div>
                        <div class="transfer-entry-line">2.</div>
                        <div class="transfer-entry-line">3.</div>
                        <div class="transfer-entry-line">4.</div>
                        <div class="transfer-entry-line">&nbsp;</div>
                        <div class="transfer-entry-line">&nbsp;</div>
                        <div class="transfer-entry-line">5.</div>
                    </td>
                    <td class="transfer-label-cell">
                        <div class="transfer-entry-line">Nama Murid</div>
                        <div class="transfer-entry-line">Nomor Induk</div>
                        <div class="transfer-entry-line">Nama Sekolah</div>
                        <div class="transfer-entry-line">Masuk di Sekolah ini:</div>
                        <div class="transfer-entry-line">a. Tanggal</div>
                        <div class="transfer-entry-line">b. Di Kelas</div>
                        <div class="transfer-entry-line">Tahun Pelajaran</div>
                    </td>
                    <td class="transfer-value-cell">
                        <div class="transfer-value-line"></div>
                        <div class="transfer-value-line"></div>
                        <div class="transfer-value-line"></div>
                        <div class="transfer-value-line blank"></div>
                        <div class="transfer-value-line"></div>
                        <div class="transfer-value-line"></div>
                        <div class="transfer-value-line"></div>
                    </td>
                    <td class="transfer-in-sign">
                        <span class="dotted"></span>
                        Kepala Sekolah,
                        <div class="transfer-sign-space"></div>
                        <span class="dotted"></span>
                        NIP.
                    </td>
                </tr>
            @endfor
        </tbody>
    </table>
</div>
</body>
</html>
