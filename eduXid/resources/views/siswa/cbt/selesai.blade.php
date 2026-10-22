<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ujian Selesai</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f3f4f6; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen">

    <div class="bg-white p-8 rounded-2xl shadow-lg w-full max-w-md text-center border-t-8 border-green-500">

        @php
            /*
             * [KONTRAK-KOREKSI] Sumber data: ExamSession (bukan flash
             * 'score' yang tidak pernah di-set). Dua keadaan:
             *   - nilai_akhir NULL  → menunggu koreksi guru (default).
             *   - nilai_akhir terisi → sudah dikonfirmasi guru: skor +
             *     jumlah benar + feedback guru ditampilkan.
             * Jumlah benar SENGAJA disembunyikan sebelum koreksi —
             * 15 benar dari 20 dengan esai belum dinilai bukan 75.
             */
            $adaSesi = isset($session) && $session
                && $session->status === 'selesai'
                && $session->waktu_submit !== null;
            $sudahNilai = $adaSesi && $session->nilai_akhir !== null;
            $feedback  = $adaSesi && $sudahNilai ? trim((string) ($session->feedback ?? '')) : '';
        @endphp

        <div class="text-6xl mb-4">{{ !$adaSesi ? '⚠️' : ($sudahNilai ? '✅' : '⏳') }}</div>

        <h1 class="text-2xl font-bold text-gray-800 mb-2">
            {{ !$adaSesi ? 'Pengumpulan Belum Terverifikasi' : ($sudahNilai ? 'Ujian Sudah Dinilai' : 'Ujian Berhasil Dikumpulkan!') }}
        </h1>
        <p class="text-gray-500 mb-6">
            {{ !$adaSesi
                ? 'Server belum dapat memastikan bahwa final submit berhasil.'
                : ($sudahNilai
                    ? 'Berikut hasil ujian Anda setelah dikoreksi guru.'
                    : 'Jawaban Anda telah tersimpan dengan aman di sistem.') }}
        </p>

        @if($adaSesi)
            <div class="bg-gray-50 p-6 rounded-xl mb-6 border border-gray-200">
                <p class="text-sm text-gray-500 font-semibold uppercase tracking-widest mb-2">
                    {{ $sudahNilai ? 'Skor Akhir Anda' : 'Status Jawaban' }}
                </p>

                @if($sudahNilai)
                    <div class="text-5xl font-extrabold text-green-600 mb-2">
                        {{ $session->nilai_akhir }}
                    </div>
                    @if(isset($total) && $total > 0 && isset($benar) && $benar !== null)
                        <p class="text-gray-700 font-medium">
                            Benar: <span class="font-bold">{{ $benar }}</span> dari {{ $total }} Soal
                        </p>
                    @endif
                @else
                    <div class="text-3xl mb-2">🗓️</div>
                    <p class="text-gray-700 font-medium">Menunggu koreksi guru</p>
                @endif
            </div>

            @if($feedback !== '')
                <div class="bg-blue-50 border border-blue-200 p-5 rounded-xl mb-6 text-left">
                    <p class="text-xs font-semibold uppercase tracking-widest text-blue-500 mb-2">💬 Feedback Guru</p>
                    <p class="text-gray-700 whitespace-pre-line">{{ $feedback }}</p>
                </div>
            @endif

            @if($session->waktu_submit)
                <p class="text-xs text-gray-400 mb-4">
                    Dikumpulkan: {{ \Carbon\Carbon::parse($session->waktu_submit)->translatedFormat('d F Y, H:i') }}
                </p>
            @endif
        @else
            <div class="bg-red-50 p-6 rounded-xl mb-6 border border-red-200">
                <p class="text-red-700 font-semibold">Status pengumpulan belum dapat diverifikasi.</p>
                <p class="text-sm text-red-600 mt-2">Silakan kembali ke halaman CBT atau hubungi pengawas.</p>
            </div>
        @endif

        <div class="text-sm text-gray-400 mb-4">
            <p>Setelah guru mengoreksi, nilai akan tercatat di buku nilai harian
               dan muncul juga di aplikasi LMS murid Anda.</p>
        </div>

        <a href="/cbt"
           class="inline-block bg-blue-600 text-white px-6 py-3 rounded-lg font-bold hover:bg-blue-700 transition-colors w-full">
            Kembali ke Login CBT
        </a>

    </div>

</body>
</html>