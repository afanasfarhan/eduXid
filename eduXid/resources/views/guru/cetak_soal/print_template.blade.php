<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Soal - {{ $judul_ujian }}</title>
    
    <!-- Panggil KaTeX Lokal -->
    <link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
    <!-- Render matematika otomatis saat script selesai dimuat -->
    <script defer src="{{ asset('katex/katex.min.js') }}"></script>
    <script defer src="{{ asset('katex/contrib/auto-render.min.js') }}" onload="renderMathInElement(document.body, {
        delimiters: [
            {left: '$$', right: '$$', display: true},
            {left: '\\[', right: '\\]', display: true},
            {left: '\\(', right: '\\)', display: false},
            {left: '$', right: '$', display: false}
        ],
        throwOnError: false
    });"></script>

    <style>
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 12pt;
            color: #000;
        }
        
        /* CSS untuk Kop Gambar */
        .kop-header {
            text-align: center;
            margin-bottom: 20px;
            display: none; /* Awalnya tersembunyi */
        }
        .kop-header img {
            width: 100%; /* Lebar penuh mengikuti halaman */
            height: auto; /* Tinggi menyesuaikan proporsi gambar */
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
        }
        /* Header format baru */
        .header h3 { margin: 0; font-size: 14pt; text-transform: uppercase; font-weight: bold; }
        .header h2 { margin: 5px 0; font-size: 14pt; text-transform: uppercase; font-weight: bold; }
        
        /* Tabel info dorong ke tengah dengan margin-left */
        .info-table { 
            width: auto; /* Lebar menyesuaikan isi teks */
            margin: 0 auto 30px 30%; /* Top, Right, Bottom, Left. 25% mendorong tabel ke tengah */
            font-size: 12pt; 
        }
        .info-table td { padding: 2px 5px; }
        .info-table td:first-child { width: 130px; font-weight: bold; }
        
        /* CSS untuk Teks Instrusi */
        .instruction-text {
            text-align: left;
            font-weight: bold; /* Cetak tebal */
            font-size: 12pt;
            margin-bottom: 10px;
            margin-left: 10px; /* Sedikit menjorok agar sejajar dengan angka soal */
        }

        .question { margin-bottom: 20px; page-break-inside: avoid; }
        .question-type-instruction { display:inline; font-size:11pt; font-weight:700; line-height:1.5; margin:0 5px 0 4px; }
        .question-text { 
            font-weight: normal; 
            margin-bottom: 8px; 
            line-height: 2.0; /* Tambahkan tinggi baris agar pecahan/teks matematika tidak menumpuk */
        }
        .options { margin-left: 30px; }
        .option { margin-bottom: 5px; display: flex; }
        .option-letter { width: 25px; }
        
        .question img, .option img {
            max-width: 100%;
            height: auto;
            margin: 5px 0;
        }
        
        @media print {
            body { margin: 0mm; }
            .no-print { display: none !important; }
            /* Nomor halaman mengikuti footer Rapor: Halaman X dari Y. */
            @page {
                size: legal;
                margin: 15mm 25mm 40mm 25mm;
                @bottom-right {
                    content: "Halaman " counter(page) " dari " counter(pages);
                    font-family: "Times New Roman", Times, serif;
                    font-size: 9pt;
                    color: #666;
                }
            }
        }
        
        /* CSS untuk Tombol di layar */
        .toolbar {
            position: fixed;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            z-index: 9999;
        }
        .btn-tool {
            padding: 10px 20px;
            cursor: pointer;
            text-decoration: none;
            font-family: sans-serif;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            color: white;
            border: none;
        }
        .btn-upload { background: #059669; } /* Hijau */
        .btn-delete { background: #dc2626; display: none; } /* Merah (Hapus Kop) */
        .btn-print { background: #4f46e5; }  /* Indigo */
        .btn-back { background: #6b7280; }   /* Abu-abu (Kembali) */
      /* V21R180: Word tables remain legible in printed question content. */
  .question-text table{border-collapse:collapse;width:100%;max-width:100%;margin:8px 0;font-size:inherit}
  .question-text td,.question-text th{border:1px solid #64748b;padding:5px 8px;text-align:left;vertical-align:top}
  .question-text tr{break-inside:avoid;page-break-inside:avoid}
</style>
</head>
<body>

    <!-- Tombol Toolbar (Akan hilang saat dicetak) -->
    <div class="toolbar no-print">
        <a href="{{ route('guru.cetakSoal') }}" class="btn-tool btn-back">⬅️ Kembali</a>
        <label for="uploadKop" class="btn-tool btn-upload" style="cursor: pointer;">
            📄 Sisipkan/Ganti Kop
        </label>
        <input type="file" id="uploadKop" accept="image/*" style="display: none;">
        <button id="btnHapusKop" class="btn-tool btn-delete">
            🗑️ Hapus Kop Soal
        </button>
        <a href="javascript:window.print()" class="btn-tool btn-print">🖨️ Cetak / Save as PDF</a>
    </div>

    <!-- Tempat Menampilkan Kop Gambar -->
    <div class="kop-header" id="kopContainer">
        <img id="kopImage" src="">
    </div>

    <div class="header">
        <h3>{{ strtoupper($judul_ujian) }}</h3>
        <h2>TAHUN PELAJARAN {{ $tahunAjaran }}</h2>
        <h2>SEMESTER {{ $semester }}</h2>
    </div>

    <table class="info-table">
        <tr>
            <td>Mata Pelajaran</td>
            <td>: {{ $mapel }}</td>
        </tr>
        <tr>
            <td>Guru</td>
            <td>: {{ $guru->nama ?? '-' }}</td>
        </tr>
        <tr>
            <td>Kelas</td>
            <td>: {{ $kelas }}</td>
        </tr>
        <tr>
            <td>Hari/Tanggal</td>
            <td>: {{ $tanggal ?? '-' }}</td>
        </tr>
        <tr>
            <td>Durasi Waktu</td>
            <td>: {{ $waktu ?? '-' }}</td>
        </tr>
    </table>

    <!-- Teks Instrusi (Sudah Cetak Tebal & di tengah) -->
    <div class="instruction-text">
        Jawablah pertanyaan berikut pada lembar jawaban yang tersedia!
    </div>

    @php $lastTkaGroup = null; @endphp
    @foreach($soals as $index => $soal)
        @if(!empty($soal->tka_group_code) && $soal->tka_group_code !== $lastTkaGroup && !empty($soal->tka_stimulus))
            <div class="question" style="background:#f5f7ff; padding:12px; border:1px solid #c7d2fe; page-break-inside:avoid;">
                <strong>Stimulus Grup {{ $soal->tka_group_code }}</strong>
                <div style="white-space:pre-line; margin-top:6px;">{{ $soal->tka_stimulus }}</div>
            </div>
        @endif
        @php $lastTkaGroup = $soal->tka_group_code ?? null; @endphp
        <div class="question">
            <div class="question-text">
                {{ $index + 1 }}.
                @if($instruction = \App\Support\QuestionTypeInstruction::forType($soal->jenis_soal))
                    <span class="question-type-instruction">{{ $instruction }}</span>
                @endif
                {!! \App\Support\QuestionHtmlSanitizer::clean($soal->pertanyaan) !!}
            </div>
            @if(!empty($soal->gambar_soal))
                <div class="question-image" style="margin: 6px 0 12px 26px; page-break-inside: avoid;">
                    <img src="{{ $soal->gambar_soal }}" alt="Gambar soal nomor {{ $index + 1 }}"
                         style="max-width: 95%; max-height: 320px; height: auto; width: auto; object-fit: contain;">
                </div>
            @endif
            
            @if(in_array($soal->jenis_soal, ['pilihan_ganda', 'majemuk']))
                <div class="options">
                    @php
                        // Pastikan opsi_jawaban adalah array
                        $opsiArr = is_string($soal->opsi_jawaban) ? json_decode($soal->opsi_jawaban, true) : $soal->opsi_jawaban;
                    @endphp
                    @if(is_array($opsiArr))
                        @foreach($opsiArr as $key => $opsi)
                            <div class="option">
                                <span class="option-letter">{{ $key }}.</span>
                                <span>{!! \App\Support\QuestionHtmlSanitizer::clean($opsi) !!}</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            @elseif($soal->jenis_soal == 'benar_salah')
                <div class="options">
                    <div class="option"><span class="option-letter">A.</span> <span>Benar</span></div>
                    <div class="option"><span class="option-letter">B.</span> <span>Salah</span></div>
                </div>
            @elseif($soal->jenis_soal == 'jawaban_singkat')
                <div class="options">
                    <div class="option">Jawaban: .........................................</div>
                </div>
            @endif
        </div>
    @endforeach

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 1. Logika Sisipkan Kop Gambar (Pure JS - Tanpa Upload Server)
            const uploadKop = document.getElementById('uploadKop');
            const kopContainer = document.getElementById('kopContainer');
            const kopImage = document.getElementById('kopImage');
            const btnHapusKop = document.getElementById('btnHapusKop');
            const KOP_STORAGE_KEY = 'eduxid_kop_soal';
            const LEGACY_KOP_STORAGE_KEY = 'eduxid_kop_surat';

            if (!localStorage.getItem(KOP_STORAGE_KEY) && localStorage.getItem(LEGACY_KOP_STORAGE_KEY)) {
                try {
                    localStorage.setItem(KOP_STORAGE_KEY, localStorage.getItem(LEGACY_KOP_STORAGE_KEY));
                } catch (err) {
                    // Jika quota browser penuh, kop lama tetap dapat dipilih ulang secara manual.
                }
            }

            // 1. Cek Local Storage saat halaman dibuka
            const savedKop = localStorage.getItem(KOP_STORAGE_KEY);
            if (savedKop) {
                kopImage.src = savedKop;
                kopContainer.style.display = 'block';
                btnHapusKop.style.display = 'block'; // Tampilkan tombol hapus
            }

            // 2. Logika Upload Kop Baru
            uploadKop.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        const dataUrl = event.target.result;
                        kopImage.src = dataUrl;
                        kopContainer.style.display = 'block'; // Tampilkan div kop
                        
                        // Simpan ke Local Storage agar permanen
                        try {
                            localStorage.setItem(KOP_STORAGE_KEY, dataUrl);
                            btnHapusKop.style.display = 'block'; // Tampilkan tombol hapus
                        } catch (err) {
                            alert("Ukuran gambar terlalu besar untuk disimpan permanen di browser. Kop hanya akan tampil untuk cetak ini saja.");
                        }
                    };
                    reader.readAsDataURL(file);
                }
            });

            // 3. Logika Hapus Kop
            btnHapusKop.addEventListener('click', function() {
                if (confirm('Yakin ingin menghapus kop soal dari browser?')) {
                    localStorage.removeItem(KOP_STORAGE_KEY);
                    kopImage.src = '';
                    kopContainer.style.display = 'none';
                    btnHapusKop.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>