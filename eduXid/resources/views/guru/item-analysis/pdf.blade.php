<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan Analisis Butir Soal</title>
<style>
    @page { margin: 24px 28px 30px 28px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5px; color: #111827; line-height: 1.35; }
    h1,h2,h3,p { margin: 0; }
    .center { text-align: center; }
    .title { font-size: 16px; font-weight: 700; letter-spacing: .5px; }
    .school { font-size: 12px; font-weight: 700; margin-top: 3px; }
    .muted { color: #6b7280; }
    .section { margin-top: 14px; margin-bottom: 5px; font-size: 10px; font-weight: 700; padding-bottom: 3px; border-bottom: 1px solid #9ca3af; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #d1d5db; padding: 4px 5px; vertical-align: top; }
    th { background: #f3f4f6; font-weight: 700; }
    .identity td:first-child, .identity td:nth-child(3) { width: 12%; font-weight: 700; background: #f9fafb; }
    .identity td:nth-child(2), .identity td:nth-child(4) { width: 38%; }
    .summary td { text-align: center; }
    .summary .big { font-size: 12px; font-weight: 700; }
    .small { font-size: 7.5px; }
    .item-table { font-size: 7px; }
    .item-table th, .item-table td { padding: 3px; }
    .nowrap { white-space: nowrap; }
    .page-break { page-break-before: always; }
    .avoid { page-break-inside: avoid; }
    .status-good { color: #166534; font-weight: 700; }
    .status-warn { color: #92400e; font-weight: 700; }
    .status-bad { color: #991b1b; font-weight: 700; }
    .note { margin-top: 10px; padding: 7px; border: 1px solid #bfdbfe; background: #eff6ff; }
    .footer-note { margin-top: 12px; font-size: 7px; color: #6b7280; }
    .math-inline { display: inline; font-family: DejaVu Sans, serif; }
    .math-display { display: block; text-align: center; margin: 3px 0; font-family: DejaVu Sans, serif; }
    .math-expression { white-space: nowrap; }
    .math-frac { display: inline-block; vertical-align: middle; text-align: center; line-height: 1.05; margin: 0 1px; }
    .math-frac-num { display: block; border-bottom: 0.7px solid #111; padding: 0 2px 1px; }
    .math-frac-den { display: block; padding: 1px 2px 0; }
    .math-radicand { border-top: 0.6px solid #111; padding-left: 2px; }
    .math-overline { text-decoration: overline; }
    .math-roman, .math-text { font-style: normal; }
    .math-vector, .math-accent { display: inline; }
    sup, sub { font-size: 70%; line-height: 0; }
</style>
</head>
<body>
@php
    $summary = $analysis['summary'] ?? [];
    $meta = $analysis['meta'] ?? [];
    $items = $analysis['items'] ?? [];
    $fmt = static fn ($value, $digits = 2) => $value === null ? '-' : number_format((float)$value, $digits, ',', '.');
    $pct = static fn ($value, $digits = 1) => $value === null ? '-' : number_format(((float)$value) * 100, $digits, ',', '.') . '%';
    $math = app(\App\Services\Assessment\PdfMathRenderer::class);
    $statusPdfClass = static fn($status) => match($status) {
        'Pertahankan' => 'status-good',
        'Bermasalah' => 'status-bad',
        default => 'status-warn',
    };
@endphp

<div class="center">
    <div class="title">LAPORAN ANALISIS BUTIR SOAL</div>
    <div class="school">{{ $meta['school_name'] ?? '-' }}</div>
    <div class="muted">NPSN: {{ $meta['school_npsn'] ?? '-' }}</div>
</div>

<div class="section">A. IDENTITAS PENILAIAN</div>
<table class="identity">
    <tr>
        <td>Nama Guru</td><td>{{ $meta['teacher_name'] ?? '-' }}</td>
        <td>Mata Pelajaran</td><td>{{ $meta['subject_name'] ?? '-' }}</td>
    </tr>
    <tr>
        <td>NIP/NIK</td><td>{{ $meta['teacher_nip'] ?? '-' }}</td>
        <td>Tingkat Kelas</td><td>{{ $meta['grade_level'] ?? '-' }}</td>
    </tr>
    <tr>
        <td>Nama Sekolah</td><td>{{ $meta['school_name'] ?? '-' }}</td>
        <td>Paket Ujian</td><td>{{ $meta['exam_title'] ?? '-' }}</td>
    </tr>
    <tr>
        <td>Sumber</td><td>{{ $analysis['source_label'] ?? '-' }}</td>
        <td>Tahun/Semester</td><td>{{ $meta['academic_year'] ?? '-' }} / {{ $meta['semester'] ?? '-' }}</td>
    </tr>
</table>

<div class="section">B. RINGKASAN HASIL TES</div>
<table class="summary">
    <tr>
        <th>Peserta</th><th>CBT</th><th>LJK</th><th>Jumlah Soal</th><th>Rata-rata</th><th>Median</th><th>Nilai Min</th><th>Nilai Maks</th><th>SD</th><th>Reliabilitas α</th>
    </tr>
    <tr>
        <td><div class="big">{{ $summary['participant_count'] ?? 0 }}</div></td>
        <td><div class="big">{{ $summary['cbt_count'] ?? 0 }}</div></td>
        <td><div class="big">{{ $summary['ljk_count'] ?? 0 }}</div></td>
        <td><div class="big">{{ $summary['question_count'] ?? 0 }}</div></td>
        <td><div class="big">{{ $fmt($summary['mean_score'] ?? null, 1) }}</div></td>
        <td><div class="big">{{ $fmt($summary['median_score'] ?? null, 1) }}</div></td>
        <td><div class="big">{{ $fmt($summary['min_score'] ?? null, 1) }}</div></td>
        <td><div class="big">{{ $fmt($summary['max_score'] ?? null, 1) }}</div></td>
        <td><div class="big">{{ $fmt($summary['stddev_score'] ?? null, 2) }}</div></td>
        <td><div class="big">{{ $fmt($summary['reliability_alpha'] ?? null, 3) }}</div><div class="small">{{ $summary['reliability_label'] ?? '-' }}</div></td>
    </tr>
</table>
@if(!empty($summary['sample_note']))
    <p style="margin-top:5px;"><strong>Catatan sampel:</strong> {{ $summary['sample_note'] }}</p>
@endif

<div class="section">C. REKAP KEPUTUSAN BUTIR</div>
<table>
    <tr>
        @foreach(['Pertahankan','Perbaiki','Tinjau','Bermasalah','Tidak Dianalisis'] as $status)
            <th>{{ $status }}</th>
        @endforeach
    </tr>
    <tr>
        @foreach(['Pertahankan','Perbaiki','Tinjau','Bermasalah','Tidak Dianalisis'] as $status)
            <td class="center"><strong>{{ (int)($summary['status_counts'][$status] ?? 0) }}</strong></td>
        @endforeach
    </tr>
</table>

<div class="section">D. ANALISIS TINGKAT KOGNITIF</div>
<table>
    <tr><th>Level Kognitif</th><th>Jumlah Soal</th><th>Rata-rata Benar</th></tr>
    @forelse($analysis['cognitive'] ?? [] as $row)
        <tr><td>{{ $row['label'] }}</td><td class="center">{{ $row['items'] }}</td><td class="center">{{ $fmt($row['mean_correct_pct'] ?? null, 1) }}%</td></tr>
    @empty
        <tr><td colspan="3" class="center muted">Belum ada data.</td></tr>
    @endforelse
</table>

<div class="section">E. ANALISIS INDIKATOR</div>
<table>
    <tr><th>Indikator</th><th style="width:12%">Jumlah Soal</th><th style="width:18%">Rata-rata Benar</th></tr>
    @forelse($analysis['indicators'] ?? [] as $row)
        <tr><td>{{ $row['label'] }}</td><td class="center">{{ $row['items'] }}</td><td class="center">{{ $fmt($row['mean_correct_pct'] ?? null, 1) }}%</td></tr>
    @empty
        <tr><td colspan="3" class="center muted">Belum ada data indikator.</td></tr>
    @endforelse
</table>

<div class="section">F. HASIL ANALISIS BUTIR SOAL LENGKAP</div>
<table class="item-table">
    <thead>
    <tr>
        <th style="width:3%">No</th>
        <th style="width:13%">Indikator</th>
        <th style="width:4%">Level</th>
        <th style="width:5%">Kunci</th>
        <th style="width:6%">Benar</th>
        <th style="width:7%">Kesukaran</th>
        <th style="width:8%">Daya Pembeda</th>
        <th style="width:6%">rpb</th>
        <th style="width:7%">α Hapus</th>
        <th style="width:7%">Status</th>
        <th style="width:15%">Dasar Keputusan</th>
        <th>Rekomendasi Perbaikan</th>
    </tr>
    </thead>
    <tbody>
    @forelse($items as $item)
        <tr>
            <td class="center">{{ $item['order'] }}</td>
            <td>{!! $math->toHtml($item['indicator'] ?: '-') !!}</td>
            <td class="center">{{ $item['cognitive_level'] ?: '-' }}</td>
            <td class="center">{{ $item['answer_key'] ?: '-' }}</td>
            <td class="center">{{ $item['correct_count'] }}/{{ $item['participant_count'] }}</td>
            <td class="center">{{ $fmt($item['difficulty_index'], 2) }}<br><span class="small">{{ $item['difficulty_label'] }}</span></td>
            <td class="center">{{ $fmt($item['discrimination_index'], 2) }}<br><span class="small">{{ $item['discrimination_label'] }}</span></td>
            <td class="center">{{ $fmt($item['point_biserial'], 2) }}<br><span class="small">{{ $item['point_biserial_label'] }}</span></td>
            <td class="center">{{ $fmt($item['alpha_if_deleted'], 3) }}</td>
            <td class="center {{ $statusPdfClass($item['status']) }}">{{ $item['status'] }}</td>
            <td>{{ $item['recommendation'] }}</td>
            <td>{{ $item['recommendation_improvement'] ?? '-' }}</td>
        </tr>
    @empty
        <tr><td colspan="12" class="center muted">Belum ada butir yang dapat dianalisis.</td></tr>
    @endforelse
    </tbody>
</table>

<div class="page-break"></div>
<div class="section" style="margin-top:0">G. DETAIL SOAL DAN ANALISIS DISTRAKTOR</div>
@forelse($items as $item)
    <div class="avoid" style="margin-bottom:10px;">
        <table>
            <tr>
                <th style="width:8%">Butir {{ $item['order'] }}</th>
                <td><strong>{!! $math->toHtml($item['stem'] ?: '-') !!}</strong></td>
            </tr>
            <tr>
                <th>Materi</th><td>{!! $math->toHtml($item['material'] ?: '-') !!}</td>
            </tr>
            <tr>
                <th>Indikator</th><td>{!! $math->toHtml($item['indicator'] ?: '-') !!}</td>
            </tr>
            <tr>
                <th>Statistik</th>
                <td>
                    N={{ $item['participant_count'] }}; Benar={{ $item['correct_count'] }}; Kosong={{ $item['blank_count'] }};
                    p={{ $fmt($item['difficulty_index'], 3) }}; D={{ $fmt($item['discrimination_index'], 3) }};
                    rpb={{ $fmt($item['point_biserial'], 3) }}; α jika dihapus={{ $fmt($item['alpha_if_deleted'], 3) }}.
                </td>
            </tr>
            <tr>
                <th>Keputusan</th>
                <td><strong class="{{ $statusPdfClass($item['status']) }}">{{ $item['status'] }}</strong> - {{ $item['recommendation'] }}</td>
            </tr>
            <tr>
                <th>Perbaikan</th>
                <td>{{ $item['recommendation_improvement'] ?? '-' }}</td>
            </tr>
        </table>
        @if(!empty($item['options']))
            <table style="margin-top:3px; font-size:7.5px;">
                <tr><th>Opsi</th><th>Keterangan</th><th>Dipilih</th><th>%</th><th>Kelompok Atas</th><th>Kelompok Bawah</th><th>Korelasi</th><th>Status</th></tr>
                @foreach($item['options'] as $opt)
                    <tr>
                        <td class="center"><strong>{{ $opt['key'] }}{{ !empty($opt['is_key']) ? ' ✓' : '' }}</strong></td>
                        <td>{!! $math->toHtml($opt['text'] ?: '-') !!}</td>
                        <td class="center">{{ $opt['count'] }}</td>
                        <td class="center">{{ $pct($opt['share'] ?? 0) }}</td>
                        <td class="center">{{ $opt['upper_count'] }}</td>
                        <td class="center">{{ $opt['lower_count'] }}</td>
                        <td class="center">{{ $fmt($opt['correlation'] ?? null, 3) }}</td>
                        <td class="center">{{ $opt['status'] }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@empty
    <p class="muted">Belum ada detail soal.</p>
@endforelse

<div class="page-break"></div>
<div class="section" style="margin-top:0">H. PENJELASAN METODE ANALISIS</div>

<div class="note">
    <strong>Kerangka analisis.</strong> eduXid menggunakan <em>Classical Test Theory (CTT)</em> untuk analisis operasional tingkat kelas/sekolah.
    Statistik dihitung dari respons final peserta pada Paket Ujian yang sama. Sumber dapat berupa CBT, LJK, atau gabungan keduanya.
    Pada sumber gabungan, identitas peserta dideduplikasi agar satu peserta tidak dihitung dua kali.
</div>

<table style="margin-top:6px;">
    <tr><th style="width:20%">Metode</th><th>Rumus / Cara Hitung</th><th style="width:34%">Interpretasi yang Digunakan eduXid</th></tr>
    <tr>
        <td><strong>Tingkat Kesukaran (p)</strong></td>
        <td>{!! $math->toHtml('$p = \\frac{B}{N}$') !!}, dengan B = jumlah peserta menjawab benar dan N = jumlah respons valid.</td>
        <td>p ≤ 0,29: Sukar; 0,30-0,70: Sedang; p ≥ 0,71: Mudah. Nilai ekstrem tidak otomatis berarti butir buruk; tujuan penilaian tetap dipertimbangkan.</td>
    </tr>
    <tr>
        <td><strong>Daya Pembeda (D)</strong></td>
        <td>{!! $math->toHtml('$D = p_{atas} - p_{bawah}$') !!}. Peserta diurutkan berdasarkan skor total, lalu digunakan sekitar 27% kelompok atas dan 27% kelompok bawah.</td>
        <td>D ≥ 0,40: Sangat baik; 0,30-0,39: Baik; 0,20-0,29: Perlu ditinjau; 0,00-0,19: Lemah; D &lt; 0: Bermasalah.</td>
    </tr>
    <tr>
        <td><strong>Corrected Item-Total / rpb</strong></td>
        <td>Korelasi Pearson antara skor butir (0/1) dengan skor total peserta setelah skor butir tersebut dikeluarkan dari total.</td>
        <td>Nilai positif tinggi menunjukkan butir sejalan dengan kemampuan yang diukur. Nilai negatif menjadi peringatan kuat untuk memeriksa kunci, redaksi, atau penskoran.</td>
    </tr>
    <tr>
        <td><strong>Analisis Distraktor</strong></td>
        <td>Menghitung frekuensi/persentase pemilihan setiap opsi, distribusi kelompok atas/bawah, dan korelasi pemilihan opsi dengan skor total terkoreksi.</td>
        <td>Distraktor yang tidak pernah dipilih atau dipilih sangat sedikit ditandai lemah/tidak berfungsi. Distraktor yang lebih menarik kelompok atas ditandai mencurigakan.</td>
    </tr>
    <tr>
        <td><strong>Reliabilitas</strong></td>
        <td>α = k/(k-1) × [1 - ΣVar(item) / Var(total)], dihitung sebagai Cronbach Alpha atas skor butir.</td>
        <td>α ≥ 0,90: Sangat tinggi; ≥0,80: Baik; ≥0,70: Cukup; ≥0,60: Perlu perhatian; &lt;0,60: Rendah. Reliabilitas tidak digunakan sendirian untuk membuang butir.</td>
    </tr>
    <tr>
        <td><strong>Alpha jika Butir Dihapus</strong></td>
        <td>Cronbach Alpha dihitung ulang dengan mengeluarkan satu butir dari tes.</td>
        <td>Digunakan sebagai sinyal tambahan apakah suatu butir menurunkan konsistensi tes; keputusan tetap harus mempertimbangkan validitas isi.</td>
    </tr>
    <tr>
        <td><strong>Indikator & Level Kognitif</strong></td>
        <td>Butir dikelompokkan berdasarkan indikator dan level kognitif, kemudian dihitung rata-rata proporsi jawaban benar.</td>
        <td>Digunakan untuk menemukan indikator atau level berpikir yang memerlukan penguatan/remedial, bukan untuk mengganti penilaian profesional guru.</td>
    </tr>
</table>

<div class="section">I. ATURAN INTERPRETASI DAN BATASAN</div>
<ul style="margin:4px 0 0 16px; padding:0;">
    <li>Ambang kategori pada laporan adalah <strong>pedoman psikometri yang dapat dikonfigurasi</strong>, bukan angka wajib dari regulasi penilaian pendidikan.</li>
    <li>Keputusan akhir harus mempertimbangkan validitas isi, kesesuaian indikator/tujuan pembelajaran, level kognitif, bahasa, konteks materi, serta tujuan tes.</li>
    <li>Sampel kecil menghasilkan statistik yang kurang stabil. eduXid memberi catatan kehati-hatian terutama bila jumlah peserta kurang dari 30.</li>
    <li>Untuk pilihan majemuk, kebenaran mengikuti aturan penskoran resmi Paket Ujian; statistik butir menggunakan hasil benar/salah final dan distribusi setiap opsi tetap dianalisis.</li>
    <li>Hasil LJK dianalisis dari jawaban yang sudah dibaca/dikoreksi Pemindai dan disahkan pada hasil koreksi. Foto LJK tidak diperlukan untuk perhitungan statistik.</li>
    <li>Rekomendasi perbaikan bersifat bantuan keputusan. Guru tetap menjadi pihak yang menetapkan apakah butir dipertahankan, direvisi, diuji ulang, atau diganti.</li>
</ul>

<div class="footer-note">
    Laporan dibuat oleh eduXid pada {{ $meta['generated_at'] ?? now()->format('d-m-Y H:i') }} · Sumber analisis: {{ $analysis['source_label'] ?? '-' }}.
</div>
</body>
</html>
