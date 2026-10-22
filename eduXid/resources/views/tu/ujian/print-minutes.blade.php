<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Berita Acara - {{ $schedule->title }} - {{ $room->room_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #111827; background: #e5e7eb; }
        .toolbar { position: sticky; top: 0; padding: 10px 16px; background: #111827; color: white; display: flex; justify-content: space-between; align-items: center; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 14px; font-weight: 700; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; margin: 10mm auto; padding: 14mm 18mm; background: white; font-size: 11pt; line-height: 1.55; }
        .print-kop { min-height: 27mm; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #111827; padding-bottom: 3mm; margin-bottom: 5mm; }
        .print-kop-image { width: 100%; max-height: 27mm; object-fit: contain; }
        .print-kop-text { text-align: center; font-size: 10pt; line-height: 1.3; }
        .school-name { font-size: 16pt; font-weight: 800; }
        h1 { font-size: 14pt; text-align: center; text-decoration: underline; margin: 2mm 0 7mm; }
        .meta { width: 100%; border-collapse: collapse; margin: 3mm 0 6mm; }
        .meta td { padding: 1mm 0; vertical-align: top; }
        .meta td:first-child { width: 38mm; font-weight: 700; }
        .summary { width: 100%; border-collapse: collapse; margin: 6mm 0; }
        .summary th, .summary td { border: 1px solid #111827; padding: 2.5mm; }
        .summary th { background: #f3f4f6; }
        .signature-grid { margin-top: 14mm; display: grid; grid-template-columns: 1fr 1fr; gap: 18mm; text-align: center; font-size: 10pt; }
        .sign-space { height: 25mm; }
        .principal { margin-top: 14mm; display: flex; justify-content: flex-end; text-align: center; font-size: 10pt; }
        .principal > div { width: 70mm; }
        .principal img { display: block; margin: 2mm auto -3mm; max-width: 38mm; max-height: 22mm; object-fit: contain; }
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none; }
            .sheet { margin: 0; width: 210mm; min-height: 297mm; padding: 14mm 18mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><div>Berita Acara Pelaksanaan Ujian</div><button onclick="window.print()">Cetak</button></div>
    <div class="sheet">
        @include('tu.ujian.partials.print-header')
        <h1>BERITA ACARA PELAKSANAAN UJIAN</h1>

        <p>
            Pada hari ini, <strong>{{ \Illuminate\Support\Carbon::parse($schedule->exam_date)->locale('id')->translatedFormat('l') }}</strong>,
            tanggal <strong>{{ \Illuminate\Support\Carbon::parse($schedule->exam_date)->locale('id')->translatedFormat('d F Y') }}</strong>,
            telah dilaksanakan ujian dengan rincian sebagai berikut:
        </p>

        <table class="meta">
            <tr><td>Program Ujian</td><td>: {{ $program->name }}</td></tr>
            <tr><td>Mata Ujian</td><td>: {{ $schedule->title }}</td></tr>
            <tr><td>Waktu</td><td>: {{ substr($schedule->start_time, 0, 5) }}{{ $schedule->end_time ? ' - ' . substr($schedule->end_time, 0, 5) : '' }}</td></tr>
            <tr><td>Ruang</td><td>: {{ $room->room_name }}</td></tr>
            <tr><td>Mode</td><td>: {{ strtoupper($schedule->mode) }}</td></tr>
        </table>

        <table class="summary">
            <thead>
                <tr>
                    <th>Peserta Terdaftar</th>
                    <th>Hadir</th>
                    <th>Tidak Hadir</th>
                    <th>Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="text-align:center"><strong>{{ $participants->count() }}</strong></td>
                    <td style="height:14mm"></td>
                    <td></td>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <p>
            Selama pelaksanaan ujian, keadaan ruang dan pelaksanaan ujian dicatat sebagai berikut:
        </p>
        <div style="border:1px solid #111827; min-height:32mm; padding:3mm;">
            ................................................................................................................................................<br>
            ................................................................................................................................................<br>
            ................................................................................................................................................<br>
            ................................................................................................................................................
        </div>

        <p>Demikian berita acara ini dibuat dengan sebenarnya untuk dapat digunakan sebagaimana mestinya.</p>

        <div class="signature-grid">
            <div>Pengawas Ruang 1,<div class="sign-space"></div><strong>____________________________</strong></div>
            <div>Pengawas Ruang 2,<div class="sign-space"></div><strong>____________________________</strong></div>
        </div>

        <div class="principal">
            <div>
                Mengetahui,<br>Kepala Sekolah
                @if(!empty($signatureUrl))
                    <img src="{{ $signatureUrl }}" alt="Tanda tangan Kepala Sekolah">
                @else
                    <div class="sign-space"></div>
                @endif
                <strong>{{ $principal->nama_lengkap ?? $principal->nama ?? $principal->name ?? '____________________________' }}</strong><br>
                @if(!empty($principal->nip)) NIP. {{ $principal->nip }} @endif
            </div>
        </div>
    </div>
</body>
</html>
