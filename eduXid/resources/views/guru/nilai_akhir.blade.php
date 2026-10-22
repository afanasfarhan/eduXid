<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Nilai Akhir &amp; Deskripsi: {{ $assignment->mapel }} - {{ $assignment->kelas }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif

            {{-- [ERROR-FIX] Dulu tidak ada blok ini: penolakan bobot ≠ 100 dari
                 controller (withErrors) tidak pernah terlihat oleh guru. --}}
            @if ($errors->any())
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <p class="font-bold mb-1">Terjadi kesalahan:</p>
                    <ul class="list-disc list-inside text-sm space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

                            @include('guru.atp.generate-description')

<form action="{{ route('guru.nilaiAkhir.store', $assignment->id) }}" method="POST" id="form-nilai-akhir">
                @csrf

                <!-- Pengaturan Bobot -->
                <div class="bg-white p-4 rounded-lg shadow-sm mb-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div class="text-sm font-bold text-gray-800">Pengaturan Bobot Penilaian (%):</div>
                        {{-- [BOBOT-LIVE] Indikator total live — guru langsung tahu
                             kenapa simpan bisa ditolak (harus tepat 100%). --}}
                        <div id="bobot-total-info" class="text-xs font-bold px-3 py-1 rounded-full border">
                            Total: <span id="bobot-total">100</span>% — harus tepat 100%
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4 max-w-md">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Diagnostik</label>
                            <input type="number" id="bobot_d" name="bobot_diagnostik" value="{{ $assignment->bobot_diagnostik }}" min="0" max="100" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm text-center" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Formatif</label>
                            <input type="number" id="bobot_f" name="bobot_formatif" value="{{ $assignment->bobot_formatif }}" min="0" max="100" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm text-center" required>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Sumatif</label>
                            <input type="number" id="bobot_s" name="bobot_sumatif" value="{{ $assignment->bobot_sumatif }}" min="0" max="100" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm text-center" required>
                        </div>
                    </div>
                </div>

                <!-- Tabel Massal -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200" id="tabel-nilai">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(0)">No</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100" onclick="sortTable(1)">Nama Siswa</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase break-words max-w-[70px] cursor-pointer hover:bg-gray-100" onclick="sortTable(2)">Diagnostik</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase break-words max-w-[70px] cursor-pointer hover:bg-gray-100" onclick="sortTable(3)">Formatif</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase break-words max-w-[70px] cursor-pointer hover:bg-gray-100" onclick="sortTable(4)">Sumatif</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase break-words max-w-[70px] cursor-pointer hover:bg-gray-100" onclick="sortTable(5)">Akhir (Otomatis)</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase break-words max-w-[70px] cursor-pointer hover:bg-gray-100" onclick="sortTable(6)">Akhir (Manual)</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase break-words max-w-[180px]">Nilai Sikap</th>
                                    <th class="px-3 py-4 text-center text-xs font-medium text-gray-500 uppercase w-2/5">Deskripsi Capaian</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">

                                @php
                                    /*
                                     * [LOOP-FIX] Definisi dimensi dipindah keluar loop —
                                     * dulu array ini dibangun ulang per siswa.
                                     */
                                    $dimensi = [
                                        1 => 'Keimanan', 2 => 'Kewargaan', 3 => 'Penalaran', 4 => 'Kreativitas',
                                        5 => 'Kolaborasi', 6 => 'Kemandirian', 7 => 'Kesehatan', 8 => 'Komunikasi',
                                    ];
                                @endphp

                                @foreach ($students as $s)
                                    <tr data-eduxid-student-id="{{ $s->id }}" data-eduxid-student-name="{{ $s->nama }}" data-avg_d="{{ $s->avg_d }}" data-avg_f="{{ $s->avg_f }}" data-avg_s="{{ $s->avg_s }}">
                                        <td class="px-2 py-3 text-center text-sm text-gray-500 nomor-urut">{{ $loop->iteration }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ mb_convert_case(strtolower($s->nama), MB_CASE_TITLE, 'UTF-8') }}
                                            <input type="hidden" name="students[{{ $s->id }}][student_id]" value="{{ $s->id }}">
                                        </td>
                                        <td class="px-2 py-3 text-center text-sm text-gray-500">{{ $s->avg_d }}</td>
                                        <td class="px-2 py-3 text-center text-sm text-gray-500">{{ $s->avg_f }}</td>
                                        <td class="px-2 py-3 text-center text-sm text-gray-500">{{ $s->avg_s }}</td>
                                        <td class="px-2 py-3 text-center text-sm font-bold text-blue-600 auto-akhir">{{ $s->calculated_akhir }}</td>
                                        <td class="px-2 py-3 text-center">
                                            {{-- [MANUAL-MARK] Penanda visual saat guru meng-override
                                                 nilai otomatis — border amber. --}}
                                            <input type="number" name="students[{{ $s->id }}][nilai_akhir]" value="{{ $s->nilai_akhir_view }}" min="0" max="100" data-manual="{{ $s->nilai_akhir_saved ? 'true' : '' }}" class="manual-akhir w-16 border-gray-300 rounded-md shadow-sm text-sm text-center p-1">
                                        </td>
                                        {{-- Tampilan 8 Dimensi (Read-Only) --}}
                                        <td class="px-2 py-3 text-center">
                                            <div class="grid grid-cols-2 gap-1">
                                                @foreach($dimensi as $i => $nama)
                                                    <div class="flex flex-col items-center bg-gray-50 p-1 rounded">
                                                        <span class="text-[10px] text-gray-600 leading-tight mb-0.5">{{ $i }}. {{ $nama }}</span>
                                                        <div class="flex justify-center">
                                                            @for($j = 1; $j <= 5; $j++)
                                                                {{-- [SIKAP-DEFAULT] Fallback 3 (Mulai Berkembang) —
                                                                     dulu ?? 4, bertentangan dengan konvensi
                                                                     seluruh sistem (SIKAP_DEFAULT = 3). --}}
                                                                <span class="text-sm {{ $j <= ($s->{'avg_d'.$i} ?? 3) ? 'text-yellow-400' : 'text-gray-300' }}">&#9733;</span>
                                                            @endfor
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <textarea name="students[{{ $s->id }}][deskripsi]" rows="10" class="w-full border-gray-300 rounded-md shadow-sm text-sm p-1" placeholder="Deskripsi kompetensi siswa...">{{ $s->deskripsi ?? '' }}</textarea>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Tombol Aksi -->
                    <div class="p-4 border-t flex flex-wrap justify-end gap-4">
                        <a href="{{ route('guru.nilaiAkhir.export', $assignment->id) }}" class="inline-flex items-center justify-center bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 font-bold text-sm shadow-sm transition-colors">
                            Download Nilai Akhir (Excel)
                        </a>
                        <button type="submit" id="btn-submit-nilai-akhir" class="inline-flex items-center justify-center bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm shadow-sm transition-colors">
                            Simpan Nilai
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Loading Overlay (Mencegah Not Responding) -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-[60] flex-col items-center justify-center">
        <div class="animate-spin rounded-full h-16 w-16 border-t-4 border-b-4 border-indigo-500 mb-4"></div>
        <p class="text-white font-bold text-lg">Sedang menyimpan data...</p>
        <p class="text-gray-300 text-sm">Mohon tunggu sebentar, jangan tutup jendela ini.</p>
    </div>

    <script>
        /*
        |--------------------------------------------------------------------------
        | 1. HITUNG OTOMATIS SAAT BOBOT BERUBAH
        |--------------------------------------------------------------------------
        |
        | CATATAN PENTING: fungsi ini SENGAJA TIDAK dipanggil saat halaman
        | dimuat — nilai manual yang sudah tersimpan oleh guru tidak boleh
        | tertimpa saat halaman dibuka. Perhitungan baru berjalan setelah
        | guru mengubah salah satu bobot.
        |
        */
        function hitungNilaiAkhir() {
            let bobot_d = parseFloat(document.getElementById('bobot_d').value) || 0;
            let bobot_f = parseFloat(document.getElementById('bobot_f').value) || 0;
            let bobot_s = parseFloat(document.getElementById('bobot_s').value) || 0;

            updateBobotInfo(bobot_d + bobot_f + bobot_s);

            document.querySelectorAll('#tabel-nilai tbody tr').forEach(row => {
                let avg_d = parseFloat(row.dataset.avg_d) || 0;
                let avg_f = parseFloat(row.dataset.avg_f) || 0;
                let avg_s = parseFloat(row.dataset.avg_s) || 0;

                const hasScore = [row.dataset.avg_d, row.dataset.avg_f, row.dataset.avg_s].some(v => v !== '' && v != null);
                let akhir = hasScore ? ((bobot_d + bobot_f + bobot_s > 0)
                    ? Math.round((avg_d * bobot_d + avg_f * bobot_f + avg_s * bobot_s) / 100)
                    : Math.round((avg_d + avg_f + avg_s) / 3)) : '';

                row.querySelector('.auto-akhir').innerText = akhir;

                let manualInput = row.querySelector('.manual-akhir');
                if (!manualInput.dataset.manual) {
                    manualInput.value = akhir;
                }
            });
        }

        /*
        |--------------------------------------------------------------------------
        | [BOBOT-LIVE] INDIKATOR TOTAL BOBOT + BLOKIR SUBMIT ≠ 100
        |--------------------------------------------------------------------------
        |
        | Controller menolak simpan bila total bobot bukan 100 (dengan
        | withErrors). Kini guru melihat total live dan submit diblokir
        | di sisi klien dengan pesan yang jelas.
        |
        */
        function updateBobotInfo(total) {
            const info = document.getElementById('bobot-total-info');
            const span = document.getElementById('bobot-total');

            if (!info || !span) return;

            span.innerText = total;

            if (total === 100) {
                info.classList.remove('bg-red-50', 'text-red-700', 'border-red-300', 'bg-green-50', 'text-green-700', 'border-green-300');
                info.classList.add('bg-green-50', 'text-green-700', 'border-green-300');
            } else {
                info.classList.remove('bg-green-50', 'text-green-700', 'border-green-300', 'bg-red-50', 'text-red-700', 'border-red-300');
                info.classList.add('bg-red-50', 'text-red-700', 'border-red-300');
            }
        }

        document.getElementById('bobot_d').addEventListener('input', hitungNilaiAkhir);
        document.getElementById('bobot_f').addEventListener('input', hitungNilaiAkhir);
        document.getElementById('bobot_s').addEventListener('input', hitungNilaiAkhir);

        document.querySelectorAll('.manual-akhir').forEach(input => {
            input.addEventListener('input', function () {
                this.dataset.manual = 'true';
                // [MANUAL-MARK] Penanda visual bahwa nilai ini hasil override.
                this.classList.remove('border-gray-300');
                this.classList.add('border-amber-400', 'ring-1', 'ring-amber-300');
            });
        });

        /*
        |--------------------------------------------------------------------------
        | 2. CEGAH SUBMIT GANDA + OVERLAY + VALIDASI BOBOT
        |--------------------------------------------------------------------------
        */
        const formNilaiAkhir = document.getElementById('form-nilai-akhir');
        const btnSubmitAkhir = document.getElementById('btn-submit-nilai-akhir');
        const loadingOverlay = document.getElementById('loading-overlay');

        if (formNilaiAkhir) {
            formNilaiAkhir.addEventListener('submit', function (event) {
                // [BOBOT-LIVE] Blokir submit bila bobot belum 100 —
                // pesan jelas sebelum server menolak.
                const bobot_d = parseFloat(document.getElementById('bobot_d').value) || 0;
                const bobot_f = parseFloat(document.getElementById('bobot_f').value) || 0;
                const bobot_s = parseFloat(document.getElementById('bobot_s').value) || 0;
                const total = bobot_d + bobot_f + bobot_s;

                if (total !== 100) {
                    event.preventDefault();
                    alert('Total bobot Diagnostik + Formatif + Sumatif harus tepat 100%.\n\nTotal saat ini: ' + total + '%.\nPerbaiki bobot terlebih dahulu, lalu simpan.');
                    return;
                }

                loadingOverlay.classList.remove('hidden');
                loadingOverlay.classList.add('flex');

                btnSubmitAkhir.disabled = true;
                btnSubmitAkhir.innerText = 'Menyimpan...';
                btnSubmitAkhir.classList.add('opacity-50', 'cursor-not-allowed');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 3. SORT TABEL REAL-TIME
        |--------------------------------------------------------------------------
        */
        function sortTable(columnIndex) {
            let table = document.getElementById('tabel-nilai');
            let tbody = table.querySelector('tbody');
            let rows = Array.from(tbody.querySelectorAll('tr'));

            let dir = table.dataset.sortDir === 'asc' ? 'desc' : 'asc';
            table.dataset.sortDir = dir;

            rows.sort((a, b) => {
                let aVal = a.cells[columnIndex].innerText.trim();
                let bVal = b.cells[columnIndex].innerText.trim();

                if (!isNaN(parseFloat(aVal)) && !isNaN(parseFloat(bVal))) {
                    aVal = parseFloat(aVal);
                    bVal = parseFloat(bVal);
                } else {
                    aVal = aVal.toLowerCase();
                    bVal = bVal.toLowerCase();
                }

                if (aVal < bVal) return dir === 'asc' ? -1 : 1;
                if (aVal > bVal) return dir === 'asc' ? 1 : -1;
                return 0;
            });

            let no = 1;
            rows.forEach(row => {
                tbody.appendChild(row);
                let noCell = row.querySelector('.nomor-urut');
                if (noCell) noCell.innerText = no++;
            });
        }
    </script>
    @include('guru.atp.final-description-buttons')
</x-app-layout>