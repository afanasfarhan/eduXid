<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Daftar Hadir - {{ $schedule->title }} - {{ $room->room_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #111827; background: #e5e7eb; }
        .toolbar { position: sticky; top: 0; padding: 10px 16px; background: #111827; color: white; display: flex; justify-content: space-between; align-items: center; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 14px; font-weight: 700; cursor: pointer; }
        .sheet { width: 210mm; min-height: 297mm; margin: 10mm auto; padding: 13mm 14mm; background: white; }
        .print-kop { min-height: 27mm; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #111827; padding-bottom: 3mm; margin-bottom: 4mm; }
        .print-kop-image { width: 100%; max-height: 27mm; object-fit: contain; }
        .print-kop-text { text-align: center; font-size: 10pt; line-height: 1.3; }
        .school-name { font-size: 16pt; font-weight: 800; }
        h1 { font-size: 14pt; margin: 2mm 0 4mm; text-align: center; }
        .meta { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 5mm; }
        .meta td { padding: 1mm 0; }
        .meta td:first-child { width: 32mm; font-weight: 700; }
        table.attendance { width: 100%; border-collapse: collapse; font-size: 9pt; }
        .attendance th, .attendance td { border: 1px solid #111827; padding: 2.2mm 1.5mm; }
        .attendance th { background: #f3f4f6; text-align: center; }
        .attendance .no { width: 10mm; text-align: center; }
        .attendance .nisn { width: 29mm; }
        .attendance .kelas { width: 24mm; }
        .attendance .ttd { width: 43mm; height: 11mm; }
        .approval { margin-top: 9mm; font-size: 9.5pt; page-break-inside: avoid; break-inside: avoid; }
        .place-date { text-align: right; margin-bottom: 6mm; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 20mm; }
        .sign { text-align: center; }
        .sign-title { margin-bottom: 1mm; }
        .sign-space { height: 20mm; }
        .sign-name { font-weight: 700; text-decoration: underline; min-height: 5mm; }
        .sign-nip { margin-top: 1mm; }
        @media print {
            @page { size: A4 portrait; margin: 0; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none; }
            .sheet { margin: 0; width: 210mm; min-height: 297mm; padding: 13mm 14mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><div>Daftar Hadir Peserta Ujian</div><button onclick="window.print()">Cetak</button></div>
    <div class="sheet">
        @include('tu.ujian.partials.print-header')
        <h1>DAFTAR HADIR PESERTA UJIAN</h1>

        <table class="meta">
            <tr><td>Program</td><td>: {{ $program->name }}</td></tr>
            <tr><td>Mata Ujian</td><td>: {{ $schedule->title }}</td></tr>
            <tr><td>Hari/Tanggal</td><td>: {{ \Illuminate\Support\Carbon::parse($schedule->exam_date)->locale('id')->translatedFormat('l, d F Y') }}</td></tr>
            <tr><td>Waktu</td><td>: {{ substr($schedule->start_time, 0, 5) }}{{ $schedule->end_time ? ' - ' . substr($schedule->end_time, 0, 5) : '' }}</td></tr>
            <tr><td>Ruang</td><td>: {{ $room->room_name }}</td></tr>
            <tr><td>Mode</td><td>: {{ strtoupper($schedule->mode) }}</td></tr>
        </table>

        <table class="attendance">
            <thead>
                <tr>
                    <th class="no">No.</th>
                    <th>Nama Peserta Ujian</th>
                    <th class="nisn">NISN</th>
                    <th class="kelas">Kelas</th>
                    <th class="ttd">Tanda Tangan</th>
                </tr>
            </thead>
            <tbody>
                @forelse($participants as $participant)
                    <tr>
                        <td class="no">{{ $loop->iteration }}</td>
                        <td>{{ $participant->nama }}</td>
                        <td>{{ $participant->nisn }}</td>
                        <td>{{ $participant->class_name ?: '-' }}</td>
                        <td class="ttd"></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center; padding:8mm">Belum ada peserta pada ruang ini.</td></tr>
                @endforelse
            </tbody>
        </table>

        @php
            $examDateLabel = \Illuminate\Support\Carbon::parse($schedule->exam_date)
                ->locale('id')
                ->translatedFormat('j F Y');
            $placeLabel = trim((string) ($examLocation ?? ''));
        @endphp

        <div class="approval">
            <div class="place-date">
                {{ $placeLabel !== '' ? $placeLabel : '..............................' }}, {{ $examDateLabel }}
            </div>

            <div class="signatures">
                <div class="sign">
                    <div class="sign-title">Pengawas 1,</div>
                    <div class="sign-space"></div>
                    <div class="sign-name">{{ $supervisor1->name ?? '____________________________' }}</div>
                    <div class="sign-nip">NIP. {{ $supervisor1->nip ?? '____________________' }}</div>
                </div>

                <div class="sign">
                    <div class="sign-title">Pengawas 2,</div>
                    <div class="sign-space"></div>
                    <div class="sign-name">{{ $supervisor2->name ?? '____________________________' }}</div>
                    <div class="sign-nip">NIP. {{ $supervisor2->nip ?? '____________________' }}</div>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:5mm;break-inside:avoid;page-break-inside:avoid;font-size:8pt">
                <div style="text-align:center;min-width:53mm">Mengetahui, Kepala Sekolah
                    @if(!empty($signatureUrl))<img src="{{ $signatureUrl }}" alt="Tanda tangan kepala sekolah" style="display:block;max-height:12mm;max-width:48mm;object-fit:contain;margin:0 auto" onerror="this.style.display='none'">@else<div style="height:12mm"></div>@endif
                    <strong>{{ $principal->nama_lengkap ?? $principal->nama ?? $principal->name ?? '____________________' }}</strong>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
