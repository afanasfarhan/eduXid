<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $data['jenis_surat'] ?? 'Surat Dinas' }}</title>

    <style>
        /* ==========================================================
           PENGATURAN HALAMAN
        ========================================================== */
        @page {
            size: legal;
            margin: 1cm 2cm 4cm 2cm;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        body {
            font-family: "Arial", Times, serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000000;
        }

        /* ==========================================================
           KERTAS SURAT
        ========================================================== */
        .surat {
            width: 100%;
            margin: 0;
            padding: 0;
        }

        /* ==========================================================
           KOP SURAT GAMBAR
        ========================================================== */
        .kop-surat {
            display: block;
            width: 100%;
            margin: 0 0 15px 0;
            padding: 0;
            text-align: center;
            page-break-inside: avoid;
            page-break-after: avoid;
        }

        .kop-surat img {
            display: block;
            width: 100%;
            max-width: 100%;
            height: auto;
            margin: 0;
            padding: 0;
        }

        /* ==========================================================
           KOP SURAT TEKS / FALLBACK
        ========================================================== */
        .kop-teks {
            width: 100%;
            margin: 0 0 15px 0;
            padding: 0;
            text-align: center;
            page-break-inside: avoid;
            page-break-after: avoid;
        }

        .kop-teks .baris-1 {
            font-size: 11pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .kop-teks .baris-2 {
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .kop-teks .baris-3 {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .kop-teks .baris-alamat {
            font-size: 10pt;
            margin-top: 2px;
        }

        .kop-teks hr {
            border: none;
            border-top: 3px solid #000000;
            margin: 8px 0 15px 0;
        }

        /* ==========================================================
           HEADER SURAT KHUSUS
           IDENTITAS KIRI
           TEMPAT DAN TANGGAL KANAN
        ========================================================== */
        .header-khusus {
            width: 100%;
            border-collapse: collapse;
            margin: 5px 0 20px 0;
            padding: 0;
            page-break-inside: avoid;
        }

        .header-khusus td {
            vertical-align: top;
            padding: 0;
            margin: 0;
        }

        .header-khusus .identitas-header {
            width: 55%;
            padding-left: 1cm;
        }

        .header-khusus .tanggal-header {
            width: 45%;
            text-align: right;
            padding-right: 10mm;
            white-space: nowrap;
        }

        .identitas-khusus {
            width: 100%;
            margin: 0;
            padding: 0;
            page-break-inside: avoid;
        }

        .identitas-khusus table {
            width: auto;
            border-collapse: collapse;
            margin: 0;
            padding: 0;
        }

        .identitas-khusus td {
            vertical-align: top;
            padding: 1px 0;
            margin: 0;
        }

        .identitas-khusus .label {
            width: 65px;
            white-space: nowrap;
        }

        .identitas-khusus .separator {
            width: 10px;
            white-space: nowrap;
        }

        .tanggal-surat-khusus {
            margin: 0;
            padding: 0;
            text-align: right;
        }

        .tanggal-surat-khusus p {
            margin: 0;
            padding: 0;
        }

        /* ==========================================================
           HEADER SURAT UMUM
        ========================================================== */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
            margin-bottom: 30px;
            page-break-inside: avoid;
        }

        .header-table > tr > td {
            vertical-align: top;
            width: 50%;
            padding: 0;
        }

        .header-table .identitas-cell {
            padding-left: 1cm;
        }

        .header-table .tujuan-cell {
            padding-left: 4cm;
        }

        .identitas-table {
            width: auto;
            border-collapse: collapse;
            margin: 0;
            padding: 0;
        }

        .identitas-table td {
            vertical-align: top;
            padding: 1px 0;
            margin: 0;
        }

        .identitas-label {
            width: 65px;
            white-space: nowrap;
        }

        .identitas-separator {
            width: 10px;
            white-space: nowrap;
        }

        .tujuan p {
            margin: 0;
            padding: 0;
            text-indent: 0;
        }

        /* ==========================================================
           BLOK PENERIMA SURAT KHUSUS
           RATA KIRI SEJAJAR DENGAN ISI SURAT
        ========================================================== */
        .tujuan-khusus {
            width: 80%;
            margin: 40px auto 40px auto;
            padding: 0;
            page-break-inside: avoid;
            text-align: left;
        }

        .tujuan-khusus p {
            margin: 0;
            padding: 0;
            text-indent: 0;
            text-align: left;
            line-height: 1.5;
            font-weight: normal;
            text-transform: none;
        }

        /* ==========================================================
           ISI SURAT
        ========================================================== */
        .isi-surat {
            width: 80%;
            margin: 0 auto;
            padding: 0;
            text-align: justify;
            line-height: 1.5;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
        }

        .isi-paragraf {
            width: 100%;
            margin: 0 0 15px 0;
            padding: 0;
            text-align: justify;
            text-indent: 0;
            white-space: normal;
            line-height: 1.5;
            font-weight: normal;
            text-transform: none;
            font-style: normal;
        }

        /* ==========================================================
           DENGAN HORMAT
           KHUSUS - TIDAK BOLEH MENGIKUTI FORMAT LAIN
        ========================================================== */
        .dengan-hormat-khusus {
            display: block !important;
            width: 100% !important;
            margin: 0 0 15px 0 !important;
            padding: 0 !important;
            text-align: left !important;
            text-indent: 0 !important;
            font-family: Arial, Times, serif !important;
            font-size: 12pt !important;
            font-weight: normal !important;
            font-style: normal !important;
            text-transform: none !important;
            text-decoration: none !important;
            letter-spacing: normal !important;
            line-height: 1.5 !important;
            color: #000000 !important;
        }

        /* ==========================================================
           TABEL DATA
        ========================================================== */
        .data-block-table {
            width: auto;
            max-width: 100%;
            border-collapse: collapse;
            margin: 0 0 15px 0;
            padding: 0;
        }

        .data-block-table td {
            vertical-align: top;
            padding: 1px 0;
            margin: 0;
            font-weight: normal;
            text-transform: none;
        }

        .data-label {
            width: 120px;
            white-space: nowrap;
        }

        .data-sep {
            width: 15px;
            text-align: center;
            white-space: nowrap;
        }

        /* ==========================================================
           JUDUL SURAT KHUSUS
        ========================================================== */
        .judul-surat {
            width: 100%;
            margin: 0 0 30px 0;
            padding: 0;
            text-align: center;
            page-break-after: avoid;
        }

        .judul-surat .judul {
            margin: 0;
            padding: 0;
            font-size: 14pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .judul-surat .nomor {
            margin: 2px 0 0 0;
            padding: 0;
            font-size: 12pt;
        }

        /* ==========================================================
           TANDA TANGAN
        ========================================================== */
        .tanda-tangan {
            width: auto;
            max-width: 50%;
            min-width: 8cm;
            margin-left: 55%;
            margin-top: 60px;
            padding: 0;
            text-align: left;
            page-break-inside: avoid;
        }

        .tanda-tangan .tempat-tanggal {
            margin: 0 0 5px 0;
            padding: 0;
            white-space: nowrap;
        }

        .tanda-tangan .jabatan {
            margin: 0;
            padding: 0;
        }

        .tanda-tangan .ruang-tanda-tangan {
            height: 75px;
            margin: 0;
            padding: 0;
        }

        .tanda-tangan .nama {
            margin: 0;
            padding: 0;
            font-weight: bold;
            text-decoration: underline;
        }

        .tanda-tangan .pangkat {
            margin: 0;
            padding: 0;
        }

        .tanda-tangan .nip {
            margin: 0;
            padding: 0;
        }

        /* ==========================================================
           MODE CETAK
        ========================================================== */
        @media print {
            html, body {
                margin: 0;
                padding: 0;
                background: #ffffff;
            }

            .no-print {
                display: none !important;
            }

            .surat {
                width: 100%;
                margin: 0;
                padding: 0;
            }

            .kop-surat {
                width: 100%;
                margin: -2cm 0 15px 0;
                padding: 0;
                text-align: center;
                page-break-inside: avoid;
                page-break-after: avoid;
            }

            .dengan-hormat-khusus {
                display: block !important;
                width: 100% !important;
                margin: 0 0 15px 0 !important;
                padding: 0 !important;
                text-align: left !important;
                text-indent: 0 !important;
                font-family: Arial, Times, serif !important;
                font-size: 12pt !important;
                font-weight: normal !important;
                font-style: normal !important;
                text-transform: none !important;
                text-decoration: none !important;
                letter-spacing: normal !important;
                line-height: 1.5 !important;
            }
        }

        /* ==========================================================
           MODE PREVIEW
        ========================================================== */
        @media screen {
            body {
                background: #e5e7eb;
            }

            .surat {
                width: 21cm;
                min-height: 29.7cm;
                padding: 1cm;
                margin: 20px auto;
                background: #ffffff;
                box-shadow: 0 0 8px rgba(0, 0, 0, 0.15);
            }

            .no-print {
                position: fixed;
                top: 15px;
                right: 15px;
                z-index: 9999;
                display: flex;
                gap: 10px;
                align-items: center;
                font-family: Arial, Helvetica, sans-serif;
            }

            .no-print a, .no-print button, .no-print label {
                border: none;
                padding: 10px 18px;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                text-decoration: none;
                white-space: nowrap;
                color: #ffffff;
            }

            .no-print input[type="file"] {
                display: none;
            }

            .kop-upload-wrapper {
                display: inline-flex;
                align-items: center;
            }

            .no-print .btn-back {
                background: #6b7280;
            }

            .no-print .btn-back:hover {
                background: #4b5563;
            }

            .no-print .btn-kop {
                background: #059669;
            }

            .no-print .btn-kop:hover {
                background: #047857;
            }

            .no-print .btn-print {
                background: #2563eb;
            }

            .no-print .btn-print:hover {
                background: #1d4ed8;
            }
        }
    </style>
</head>

<body>

    {{-- ==========================================================
         TOMBOL AKSI
    ========================================================== --}}
    <div class="no-print">
        <a href="{{ route('tu.surat.index') }}" class="btn-back">Kembali</a>
        <div class="kop-upload-wrapper">
            <label for="inputKopSurat" class="btn-kop">Kop Surat</label>
            <input type="file" id="inputKopSurat" accept="image/png, image/jpeg, image/jpg, image/webp">
        </div>
        <button type="button" class="btn-print" onclick="window.print()">Cetak Surat</button>
    </div>

    {{-- ==========================================================
         KERTAS SURAT
    ========================================================== --}}
    <div class="surat">

        {{-- ======================================================
             KOP SURAT
        ====================================================== --}}
        @php
            $kopSurat = $data['kop_surat'] ?? null;
            $school = $data['school'] ?? [];
        @endphp

        @if ($kopSurat)
            <div class="kop-surat">
                @php
                    $kopUrl = app(\App\Services\LocalMediaService::class)->url($kopSurat);
                @endphp
                <img src="{{ $kopUrl }}" alt="Kop Surat">
            </div>
        @elseif (!empty($school))
            <div class="kop-teks">
                @if (!empty($school['kabupaten_kota']) || !empty($school['provinsi']))
                    <div class="baris-1">
                        {{ $school['kabupaten_kota'] ?? '' }}
                        @if (!empty($school['provinsi'])) , {{ $school['provinsi'] }} @endif
                    </div>
                @endif

                @if (!empty($school['nama_dinas']) || !empty($school['nama_yayasan']))
                    <div class="baris-2">
                        {{ $school['nama_dinas'] ?? $school['nama_yayasan'] ?? '' }}
                    </div>
                @endif

                <div class="baris-3">
                    {{ $school['nama'] ?? 'NAMA SEKOLAH' }}
                </div>

                <div class="baris-alamat">
                    {{ $school['alamat_jalan'] ?? '' }} {{ $school['kode_pos'] ?? '' }}
                </div>
                <hr>
            </div>
        @endif

        {{-- ==========================================================
             DETEKSI SURAT KHUSUS / UMUM & PARSING ISI
        ========================================================== --}}
        @php
            $jenisSurat = $data['jenis_surat'] ?? '';
            $khususList = ['Keterangan', 'Tugas', 'Pernyataan', 'Rekomendasi', 'Dispensasi', 'Undangan', 'Panggilan', 'Teguran', 'Peringatan', 'Permohonan', 'Pemberitahuan'];
            $isSuratKhusus = false;

            foreach ($khususList as $k) {
                if (stripos($jenisSurat, $k) !== false) {
                    $isSuratKhusus = true;
                    break;
                }
            }

            // Daftar surat yang TETAP menampilkan blok atas (Nomor, Lampiran, Perihal, Tempat-Tanggal, Kepada Yth)
            $topBlockList = ['Undangan', 'Panggilan', 'Teguran', 'Peringatan', 'Permohonan', 'Pemberitahuan'];
            $showTopBlock = false;
            foreach ($topBlockList as $k) {
                if (stripos($jenisSurat, $k) !== false) {
                    $showTopBlock = true;
                    break;
                }
            }

            $judulSurat = '';
            $nomorSuratText = '';
            $sisaIsiSurat = '';

            if ($isSuratKhusus) {
                $isiRaw = trim($data['isi_surat'] ?? '');
                $firstLine = strtok($isiRaw, "\n");
                
                // PENCEGAHAN ERROR: Cek apakah baris pertama BENAR-BENAR sebuah Judul Surat.
                $isTitleValid = preg_match('/^(surat|undangan|panggilan|keterangan|tugas|pernyataan|rekomendasi|dispensasi|teguran|peringatan|permohonan|pemberitahuan)/i', trim($firstLine));
                
                if ($isTitleValid) {
                    $parts = explode("\n", $isiRaw, 3);
                    $judulSurat = $parts[0] ?? '';
                    $nomorSuratText = $parts[1] ?? '';
                    $sisaIsiSurat = isset($parts[2]) ? ltrim($parts[2]) : '';
                } else {
                    $sisaIsiSurat = $isiRaw;
                }
            }

            $isiToParse = $isSuratKhusus ? $sisaIsiSurat : ($data['isi_surat'] ?? '');

            // NORMALISASI DENGAN HORMAT
            $isiToParse = preg_replace(
                '/\s*(dengan\s+hormat\s*,)\s*/iu',
                "\n\nDengan hormat,\n\n",
                $isiToParse
            );

            $parsedBlocks = [];

            if (trim($isiToParse) !== '') {
                $isiToParse = str_replace(["\r\n", "\r"], "\n", $isiToParse);
                $paragraphs = preg_split("/\n\s*\n/", trim($isiToParse));

                foreach ($paragraphs as $para) {
                    $para = trim($para);
                    if ($para === '') continue;

                    $knownLabels = [
                        'Nama', 'NIP', 'NIS', 'NISN', 'NIPD', 'Kelas',
                        'Pangkat/Gol', 'Pangkat', 'Golongan', 'Jabatan',
                        'Unit Kerja', 'Hari/Tanggal', 'Hari', 'Tanggal',
                        'Pukul', 'Waktu', 'Tempat', 'Acara/Kegiatan',
                        'Keterangan', 'Keperluan'
                    ];

                    $labelPattern = implode('|', array_map(function ($label) {
                        return preg_quote($label, '/');
                    }, $knownLabels));

                    $pattern = '/(' . $labelPattern . ')\s*:\s*(.*?)(?=\s+(?:' . $labelPattern . ')\s*:|$)/isu';

                    preg_match_all($pattern, $para, $matches, PREG_SET_ORDER);

                    $dataRows = [];
                    if (!empty($matches)) {
                        foreach ($matches as $match) {
                            $label = trim($match[1]);
                            $value = trim($match[2]);
                            if ($label !== '' && $value !== '') {
                                $dataRows[] = ['label' => $label, 'value' => $value];
                            }
                        }
                    }

                    if (count($dataRows) > 0) {
                        $parsedBlocks[] = ['type' => 'table', 'rows' => $dataRows];
                        continue;
                    }

                    $cleanLines = preg_split("/\n/", $para);
                    $cleanLines = array_map('trim', $cleanLines);
                    $cleanLines = array_filter($cleanLines, function ($line) {
                        return $line !== '';
                    });

                    $content = implode(' ', $cleanLines);

                    if (preg_match('/^\s*dengan\s+hormat\s*,\s*$/iu', $content)) {
                        $parsedBlocks[] = ['type' => 'dengan_hormat', 'content' => 'Dengan hormat,'];
                    } else {
                        $parsedBlocks[] = ['type' => 'text', 'content' => $content];
                    }
                }
            }

            // Tentukan nomor untuk di bawah judul
            $nomorUntukJudul = '';
            if (!$showTopBlock) {
                $nomorUntukJudul = !empty($data['nomor_surat']) ? 'Nomor: ' . $data['nomor_surat'] : $nomorSuratText;
            } else {
                $nomorUntukJudul = $nomorSuratText;
            }
        @endphp

        {{-- ==========================================================
             TEMPAT DAN TANGGAL SURAT
        ========================================================== --}}
        @php
            $tempatSurat = trim((string) ($data['tempat_surat'] ?? ''));
            if ($tempatSurat === '') $tempatSurat = 'Tempat';
            $tanggalSurat = $data['tanggal_surat_indonesia'] ?? ($data['tanggal_surat'] ?? '');
        @endphp

        {{-- ==========================================================
             SURAT KHUSUS DENGAN BLOK ATAS (Undangan, Panggilan, Teguran, Peringatan, Permohonan, Pemberitahuan)
        ========================================================== --}}
        @if ($isSuratKhusus && $showTopBlock)
            <table class="header-khusus">
                <tr>
                    <td class="identitas-header">
                        @if (!empty($data['nomor_surat']) || !empty($data['lampiran']) || !empty($data['perihal']))
                            <div class="identitas-khusus">
                                <table>
                                    <tr>
                                        <td class="label">Nomor</td>
                                        <td class="separator">:</td>
                                        <td>{{ $data['nomor_surat'] ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <td class="label">Lampiran</td>
                                        <td class="separator">:</td>
                                        <td>{{ $data['lampiran'] ?? '-' }}</td>
                                    </tr>
                                    <tr>
                                        <td class="label">Perihal</td>
                                        <td class="separator">:</td>
                                        <td>{{ $data['perihal'] ?? '-' }}</td>
                                    </tr>
                                </table>
                            </div>
                        @endif
                    </td>
                    <td class="tanggal-header">
                        <div class="tanggal-surat-khusus">
                            <p>{{ $tempatSurat }}, {{ $tanggalSurat }}</p>
                        </div>
                    </td>
                </tr>
            </table>

            @if (!empty($data['tujuan']) || !empty($data['nama_siswa']) || !empty($data['kelas']) || !empty($data['alamat_tujuan']))
                <div class="tujuan-khusus">
                    <p>Kepada Yth.</p>
                    @if (!empty($data['tujuan'])) <p>{{ $data['tujuan'] }}</p> @endif
                    @if (!empty($data['nama_siswa'])) <p>{{ $data['nama_siswa'] }}</p> @endif
                    @if (!empty($data['kelas'])) <p>Kelas {{ $data['kelas'] }}</p> @endif
                    @if (!empty($data['alamat_tujuan'])) <p>{{ $data['alamat_tujuan'] }}</p> @endif
                </div>
            @endif
        @endif

        {{-- ==========================================================
             HEADER SURAT UMUM
        ========================================================== --}}
        @if (!$isSuratKhusus && (!empty($data['nomor_surat']) || !empty($data['perihal']) || !empty($data['tujuan']) || !empty($data['alamat_tujuan'])))
            <table class="header-table">
                <tr>
                    <td class="identitas-cell">
                        <table class="identitas-table">
                            <tr>
                                <td class="identitas-label">Nomor</td>
                                <td class="identitas-separator">:</td>
                                <td>{{ $data['nomor_surat'] ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td class="identitas-label">Lampiran</td>
                                <td class="identitas-separator">:</td>
                                <td>{{ $data['lampiran'] ?? '-' }}</td>
                            </tr>
                            <tr>
                                <td class="identitas-label">Perihal</td>
                                <td class="identitas-separator">:</td>
                                <td>{{ $data['perihal'] ?? '-' }}</td>
                            </tr>
                        </table>
                    </td>
                    <td class="tujuan-cell">
                        @if (!empty($data['tujuan']) || !empty($data['nama_siswa']) || !empty($data['kelas']) || !empty($data['alamat_tujuan']))
                            <div class="tujuan">
                                <p>Kepada Yth.</p>
                                @if (!empty($data['tujuan'])) <p>{{ $data['tujuan'] }}</p> @endif
                                @if (!empty($data['nama_siswa'])) <p>{{ $data['nama_siswa'] }}</p> @endif
                                @if (!empty($data['kelas'])) <p>Kelas {{ $data['kelas'] }}</p> @endif
                                @if (!empty($data['alamat_tujuan'])) <p>{{ $data['alamat_tujuan'] }}</p> @endif
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        {{-- ==========================================================
             JUDUL SURAT KHUSUS
             (Jika tidak ada blok atas, nomor surat diletakkan di bawah judul)
        ========================================================== --}}
        @if ($isSuratKhusus && !empty($judulSurat))
            <div class="judul-surat">
                <p class="judul">{{ $judulSurat }}</p>
                @if (!empty($nomorUntukJudul))
                    <p class="nomor">{{ $nomorUntukJudul }}</p>
                @endif
            </div>
        @endif

        {{-- ==========================================================
             ISI SURAT
        ========================================================== --}}
        <div class="isi-surat">
            @foreach ($parsedBlocks as $block)
                @if ($block['type'] === 'table')
                    <table class="data-block-table">
                        @foreach ($block['rows'] as $row)
                            <tr>
                                <td class="data-label">{{ $row['label'] }}</td>
                                <td class="data-sep">:</td>
                                <td>{{ $row['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @elseif ($block['type'] === 'dengan_hormat')
                    <div class="dengan-hormat-khusus">
                        Dengan hormat,
                    </div>
                @else
                    <p class="isi-paragraf">
                        {{ $block['content'] }}
                    </p>
                @endif
            @endforeach
        </div>

        {{-- ==========================================================
             TANDA TANGAN
        ========================================================== --}}
        @php
            $jabatanPenandatangan = trim((string) ($data['penandatangan_jabatan'] ?? 'Kepala Sekolah'));
            $namaPenandatangan = trim((string) ($data['nama_penanda_tangan'] ?? ''));
            $nipPenandatangan = trim((string) ($data['nip_penanda_tangan'] ?? ''));
            $pangkatGolongan = trim((string) (
                $data['penandatangan_pangkat_golongan']
                ?? $data['pangkat_golongan']
                ?? ''
            ));
        @endphp

        <div class="tanda-tangan">
            {{-- Jika bukan surat dengan blok atas (Undangan/Panggilan/Teguran/Peringatan/Permohonan/Pemberitahuan), 
                 tempat dan tanggal ditampilkan di atas tanda tangan --}}
            @if (!$showTopBlock)
                <p class="tempat-tanggal">{{ $tempatSurat }}, {{ $tanggalSurat }}</p>
            @endif

            <p class="jabatan">{{ $jabatanPenandatangan }}</p>
            <div class="ruang-tanda-tangan"></div>
            <p class="nama">{{ $namaPenandatangan ?: '................................................' }}</p>

            @if ($pangkatGolongan !== '')
                <p class="pangkat">{{ $pangkatGolongan }}</p>
            @endif

            @if ($nipPenandatangan !== '')
                <p class="nip">NIP. {{ $nipPenandatangan }}</p>
            @else
                <p class="nip">NIP. ....................................</p>
            @endif
        </div>

    </div>

    {{-- ==========================================================
         JAVASCRIPT KOP SURAT
    ========================================================== --}}
    <script>
        const KOP_DB_NAME = 'eduXidSuratDB';
        const KOP_DB_VERSION = 1;
        const KOP_STORE_NAME = 'kop_surat';
        const KOP_KEY = 'kop_utama';

        function bukaDatabaseKop() {
            return new Promise(function (resolve, reject) {
                const request = indexedDB.open(KOP_DB_NAME, KOP_DB_VERSION);
                request.onupgradeneeded = function (event) {
                    const db = event.target.result;
                    if (!db.objectStoreNames.contains(KOP_STORE_NAME)) {
                        db.createObjectStore(KOP_STORE_NAME);
                    }
                };
                request.onsuccess = function (event) { resolve(event.target.result); };
                request.onerror = function () { reject(request.error); };
            });
        }

        async function simpanKopSurat(dataUrl) {
            try {
                const db = await bukaDatabaseKop();
                return new Promise(function (resolve, reject) {
                    const transaction = db.transaction(KOP_STORE_NAME, 'readwrite');
                    const store = transaction.objectStore(KOP_STORE_NAME);
                    const request = store.put(dataUrl, KOP_KEY);
                    request.onsuccess = function () { resolve(true); };
                    request.onerror = function () { reject(request.error); };
                });
            } catch (error) {
                console.error('Gagal menyimpan kop surat:', error);
                return false;
            }
        }

        async function ambilKopSurat() {
            try {
                const db = await bukaDatabaseKop();
                return new Promise(function (resolve, reject) {
                    const transaction = db.transaction(KOP_STORE_NAME, 'readonly');
                    const store = transaction.objectStore(KOP_STORE_NAME);
                    const request = store.get(KOP_KEY);
                    request.onsuccess = function () { resolve(request.result || null); };
                    request.onerror = function () { reject(request.error); };
                });
            } catch (error) {
                console.error('Gagal mengambil kop surat:', error);
                return null;
            }
        }

        function tampilkanKopSurat(kopUrl) {
            if (!kopUrl) return;
            const surat = document.querySelector('.surat');
            if (!surat) { console.error('Area surat tidak ditemukan.'); return; }

            let kopContainer = surat.querySelector('.kop-surat');
            if (!kopContainer) {
                kopContainer = document.createElement('div');
                kopContainer.className = 'kop-surat';
                surat.insertBefore(kopContainer, surat.firstChild);
            }

            kopContainer.innerHTML = '';
            const img = document.createElement('img');
            img.src = kopUrl;
            img.alt = '';
            img.style.display = 'block';
            img.style.width = '100%';
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
            img.style.margin = '0';
            img.style.padding = '0';
            kopContainer.appendChild(img);

            kopContainer.style.display = 'block';
            kopContainer.style.width = '100%';
            kopContainer.style.margin = '0 0 15px 0';
            kopContainer.style.padding = '0';

            const kopTeks = surat.querySelector('.kop-teks');
            if (kopTeks) { kopTeks.style.display = 'none'; }
        }

        async function loadKopTersimpan() {
            const kopTersimpan = await ambilKopSurat();
            if (kopTersimpan) { tampilkanKopSurat(kopTersimpan); }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const inputKopSurat = document.getElementById('inputKopSurat');
            if (!inputKopSurat) { console.error('Input kop surat tidak ditemukan.'); return; }

            loadKopTersimpan();

            inputKopSurat.addEventListener('change', async function (event) {
                const file = event.target.files[0];
                if (!file) return;

                const allowedTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    alert('File kop surat harus berupa PNG, JPG, JPEG, atau WEBP.');
                    event.target.value = '';
                    return;
                }

                if (file.size > 5 * 1024 * 1024) {
                    alert('Ukuran file kop surat maksimal 5 MB.');
                    event.target.value = '';
                    return;
                }

                const reader = new FileReader();
                reader.onload = async function (e) {
                    const kopUrl = e.target.result;
                    tampilkanKopSurat(kopUrl);
                    const berhasil = await simpanKopSurat(kopUrl);
                    if (!berhasil) {
                        alert('Kop berhasil ditampilkan, tetapi gagal disimpan untuk surat berikutnya.');
                    }
                    event.target.value = '';
                };

                reader.onerror = function () {
                    alert('Gagal membaca file kop surat.');
                    event.target.value = '';
                };

                reader.readAsDataURL(file);
            });
        });

        @if (request()->query('autoprint') === '1')
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 300);
            });
        @endif

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (confirm('Kembali ke form surat?')) {
                    window.location.href = '{{ route('tu.surat.index') }}';
                }
            }
        });
    </script>

</body>
</html>