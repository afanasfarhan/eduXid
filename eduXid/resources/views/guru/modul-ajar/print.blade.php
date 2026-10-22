<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modul Ajar — {{ $module->title }}</title>
<link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
<style>
@page { size: A4 portrait; margin: 17mm 17mm 16mm; }
*{box-sizing:border-box}body{font-family:Arial, sans-serif;color:#20262f;margin:24px auto;max-width:900px;font-size:11pt;line-height:1.5}
h1{font-size:18pt;margin:5px 0;text-align:center}h2{font-size:12pt;border-bottom:1px solid #b8c3d3;padding-bottom:5px;margin-top:18px}h3{font-size:10.5pt;margin:10px 0 4px}p{margin:3px 0;white-space:pre-wrap;overflow-wrap:anywhere}.meta{width:100%;border-collapse:collapse;margin:15px 0}.meta th,.meta td{padding:6px;border:1px solid #c5cfdd;text-align:left;vertical-align:top}.meta th{background:#f6f8fc;width:25%}.muted{color:#666;text-align:center}.actions{text-align:right;margin-bottom:16px}.actions button{padding:10px 18px;background:#2563eb;color:white;border:0;border-radius:7px;cursor:pointer}.box{break-inside:avoid}.dimensi{margin:8px 0}footer{margin-top:25px;text-align:center;font-size:8pt;color:#777}@media print{body{margin:0;max-width:none}.actions{display:none}}
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<h1>MODUL AJAR</h1>
<p class="muted">Kurikulum Merdeka · Pembelajaran Mendalam (Deep Learning)</p>
<table class="meta">
<tr><th>Judul</th><td>{{ $module->title }}</td></tr>
<tr><th>Guru</th><td>{{ $teacher->nama ?? '—' }}</td></tr>
<tr><th>Mata Pelajaran</th><td>{{ $subject ?? '—' }}</td></tr>
<tr><th>Kelas / Fase</th><td>{{ $module->grade_level }} / {{ $module->phase }}</td></tr>
<tr><th>Semester</th><td>{{ $module->semester_id ?? '—' }}</td></tr>
<tr><th>Alokasi Waktu</th><td>{{ $module->duration_jp }} JP</td></tr>
</table>
@php
$groups = [
    'Identifikasi' => ['karakteristik'=>'Karakteristik dan Kesiapan Murid'],
    'ATP dan Tujuan' => ['cp'=>'Capaian Pembelajaran (CP)','materi'=>'Materi','tujuan'=>'Tujuan Pembelajaran','indikator'=>'Indikator Penilaian','pemantik'=>'Pertanyaan Pemantik'],
    'Profil Lulusan & Prinsip' => ['profil_strategi'=>'Strategi Dimensi Profil Lulusan'],
    'Desain Pembelajaran' => ['pedagogi'=>'Praktik Pedagogis','kemitraan'=>'Kemitraan','lingkungan'=>'Lingkungan Belajar','digital'=>'Pemanfaatan Digital','diferensiasi'=>'Diferensiasi'],
    'Pengalaman Belajar' => ['pendahuluan'=>'Pendahuluan','memahami'=>'Memahami','mengaplikasi'=>'Mengaplikasi','merefleksi'=>'Merefleksi','penutup'=>'Penutup'],
    'Asesmen' => ['diagnostik'=>'Asesmen Awal / Diagnostik','formatif'=>'Asesmen Proses / Formatif','sumatif'=>'Asesmen Akhir / Sumatif','rubrik'=>'Rubrik dan Bukti Belajar','remedial'=>'Remedial / Pengayaan'],
    'Lampiran dan Refleksi' => ['lampiran'=>'Lampiran / Referensi','refleksi_guru'=>'Refleksi Guru']
];
@endphp
@foreach($groups as $heading => $items)
<section>
<h2>{{ $heading }}</h2>
@if($heading === 'Profil Lulusan & Prinsip')
    <div class="dimensi"><strong>Dimensi Profil:</strong> {{ collect($content['dimensi'] ?? [])->map(fn($d) => $d.' '.(\App\Services\ModulAjarGenerator::DIMENSIONS[$d] ?? ''))->join(', ') ?: 'Belum dipilih' }}</div>
    <div class="dimensi"><strong>Prinsip:</strong> {{ implode(', ', $content['prinsip'] ?? []) ?: 'Belum dipilih' }}</div>
@endif
@foreach($items as $key => $label)
    <div class="box"><h3>{{ $label }}</h3><p>{{ $content[$key] ?? 'Belum diisi.' }}</p></div>
@endforeach
</section>
@endforeach
<footer>Dokumen Modul Ajar eduXid · Draf guru, perlu verifikasi dan penyesuaian sebelum digunakan</footer>
<script src="{{ asset('katex/katex.min.js') }}"></script>
<script src="{{ asset('katex/contrib/auto-render.min.js') }}"></script>
<script>document.addEventListener('DOMContentLoaded', function(){if(window.renderMathInElement)renderMathInElement(document.body,{delimiters:[{left:'$$',right:'$$',display:true},{left:'\\(',right:'\\)',display:false},{left:'\\[',right:'\\]',display:true}],throwOnError:false});});</script>
</body>
</html>
