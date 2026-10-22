<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Input Nilai: {{ $assignment->mapel }} - {{ $assignment->kelas }}
            @if($tanggal_edit)
                <span class="text-red-500 text-sm">(Mode Edit: {{ $tanggal_edit }})</span>
            @endif
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    {{ session('success') }}
                </div>
            @endif

            {{-- [ERROR-FIX] Dulu tidak ada: kegagalan validasi (status invalid,
                 nilai di luar 0-100, dsb.) menampilkan ulang form TANPA penjelasan. --}}
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

            @if (session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    {{ session('error') }}
                </div>
            @endif

            <!-- Panduan Penilaian -->
            <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-6 rounded-r-lg text-sm">
                <p class="font-bold mb-2">Panduan Penilaian:</p>
                <ul class="list-disc list-inside space-y-1">
                    <li><strong>Diagnostik:</strong> Penilaian awal sebelum materi. (Contoh: kuis prasyarat).</li>
                    <li><strong>Formatif:</strong> Penilaian proses selama pembelajaran. (Contoh: tugas, lembar kerja, praktik)</li>
                    <li><strong>Sumatif:</strong> Penilaian akhir materi/tujuan pembelajaran. (Contoh: penilaian akhir bab, PSTS, PSAS)</li>
                    <li><strong>Sikap:</strong> Rating bintang 8 Dimensi Profil Lulusan (default: 3 bintang — Mulai Berkembang).</li>
                </ul>
            </div>

            {{-- [EDIT-MODE] Pita info + link keluar dari mode edit --}}
            @if($tanggal_edit)
                <div class="bg-amber-50 border border-amber-200 text-amber-800 px-4 py-3 rounded-lg mb-6 text-sm flex flex-wrap items-center justify-between gap-2">
                    <span>
                        Anda sedang <b>mengedit pertemuan {{ $tanggal_edit }}</b>.
                        Perubahan akan menimpa data pertemuan tersebut (data lebih baru dari HP tetap dilindungi).
                    </span>
                    <a href="{{ route('guru.nilai', ['id' => $assignment->id]) }}"
                       class="inline-flex items-center gap-1 bg-white border border-amber-300 text-amber-800 px-3 py-1 rounded-md font-bold text-xs hover:bg-amber-100 transition-colors">
                        ✖ Batal Edit — input pertemuan baru
                    </a>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-8">
                <form action="{{ route('guru.nilai.store', $assignment->id) }}" method="POST" id="form-nilai">
                    @csrf

                    <!-- Baris Jam ke- / Pukul / Tanggal & Materi -->
                    <div class="p-4 border-b grid grid-cols-5 gap-3 items-end">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Jam ke-</label>
                            <input type="text" name="jam_ke" maxlength="20" value="{{ old('jam_ke', $jam_ke_edit ?? '') }}" placeholder="mis. 3" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Pukul</label>
                            <input type="text" name="pukul" maxlength="50" value="{{ old('pukul', $pukul_edit ?? '') }}" placeholder="mis. 07.30 - 09.00" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" autocomplete="off">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal Pertemuan</label>
                            {{-- [EDIT-LOCK] Tanggal terkunci pada mode edit agar edit
                                 selalu ter-scope ke pertemuan yang benar; mengubah
                                 tanggal bisa meng-clone/menimpa data tanggal lain. --}}
                            <input type="date" name="tanggal" value="{{ old('tanggal', $tanggal_edit ?? date('Y-m-d')) }}"
                                   @readonly((bool) $tanggal_edit)
                                   class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm @if($tanggal_edit) bg-gray-100 text-gray-500 @endif" required>
                            @if($tanggal_edit)
                                <p class="text-[11px] text-gray-400 mt-1">Terkunci pada mode edit.</p>
                            @endif
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Materi/Tujuan Pembelajaran</label>
                                                @include('guru.atp.selector-nilai')

<input type="text" name="materi" maxlength="255" value="{{ old('materi', $materi_edit) }}" placeholder="Contoh: Listrik Arus Searah" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" autocomplete="off">
                        </div>
                    </div>

                    <!-- Tabel Massal Input -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 table-fixed" id="tabel-nilai">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-12 cursor-pointer hover:bg-gray-100" onclick="sortTable(0)">No</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-32 cursor-pointer hover:bg-gray-100" onclick="sortTable(1)">NISN</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-48 cursor-pointer hover:bg-gray-100" onclick="sortTable(2)">Nama Murid</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-24">Absensi</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20 cursor-pointer hover:bg-gray-100" onclick="sortTable(4)">Diagnostik</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20 cursor-pointer hover:bg-gray-100" onclick="sortTable(5)">Formatif</th>
                                    <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20 cursor-pointer hover:bg-gray-100" onclick="sortTable(6)">Sumatif</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Penilaian Sikap</th>
                                    <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase w-44">📝 Catatan Murid</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">

                                @php
                                    /*
                                     * [LOOP-FIX] Definisi dimensi dipindah keluar loop —
                                     * dulu array dibangun ulang per siswa.
                                     */
                                    $dimensi = [
                                        1 => 'Keimanan',
                                        2 => 'Kewargaan',
                                        3 => 'Penalaran',
                                        4 => 'Kreativitas',
                                        5 => 'Kolaborasi',
                                        6 => 'Kemandirian',
                                        7 => 'Kesehatan',
                                        8 => 'Komunikasi',
                                    ];
                                @endphp

                                @foreach ($students as $s)
                                    @php
                                        /*
                                         * [OLD-FIX] old() dipakai di SEMUA field — dulu hanya
                                         * catatan_siswa. Tanpa ini, satu nilai salah (mis. 150)
                                         * menggagalkan validasi dan MENGHAPUS seluruh input
                                         * yang belum disimpan untuk 30+ siswa.
                                         */
                                        $old_score = $edit_scores->get($s->id);
                                        $old_att = $edit_attendances->get($s->id);

                                        $oldStatus = old('students.' . $s->id . '.status', $old_att?->status ?? 'Hadir');
                                    @endphp
                                    <tr data-eduxid-student-id="{{ $s->id }}" data-eduxid-student-nisn="{{ $s->nisn }}" data-eduxid-student-name="{{ $s->nama }}">
                                        <td class="px-2 py-3 text-center text-sm text-gray-500 nomor-urut">{{ $loop->iteration }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-900 font-mono">
                                            {{ $s->nisn }}
                                        </td>
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900 truncate">
                                            {{ mb_convert_case(strtolower($s->nama), MB_CASE_TITLE, 'UTF-8') }}
                                            <input type="hidden" name="students[{{ $s->id }}][student_id]" value="{{ $s->id }}">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <select name="students[{{ $s->id }}][status]" class="border-gray-300 rounded-md shadow-sm text-sm p-1 w-full">
                                                <option value="Hadir" @selected($oldStatus === 'Hadir')>Hadir</option>
                                                <option value="Sakit" @selected($oldStatus === 'Sakit')>Sakit</option>
                                                <option value="Izin" @selected($oldStatus === 'Izin')>Izin</option>
                                                <option value="Alpa" @selected($oldStatus === 'Alpa')>Alpa</option>
                                            </select>
                                        </td>
                                        <td class="px-2 py-3 text-center">
                                            <input type="number" name="students[{{ $s->id }}][diagnostik]" min="0" max="100" step="1" value="{{ old('students.' . $s->id . '.diagnostik', $old_score->diagnostik ?? '') }}" class="w-full border-gray-300 rounded-md shadow-sm text-sm text-center p-1" placeholder="-" autocomplete="off">
                                        </td>
                                        <td class="px-2 py-3 text-center">
                                            <input type="number" name="students[{{ $s->id }}][formatif]" min="0" max="100" step="1" value="{{ old('students.' . $s->id . '.formatif', $old_score->formatif ?? '') }}" class="w-full border-gray-300 rounded-md shadow-sm text-sm text-center p-1" placeholder="-" autocomplete="off">
                                        </td>
                                        <td class="px-2 py-3 text-center">
                                            <input type="number" name="students[{{ $s->id }}][sumatif]" min="0" max="100" step="1" value="{{ old('students.' . $s->id . '.sumatif', $old_score->sumatif ?? '') }}" class="w-full border-gray-300 rounded-md shadow-sm text-sm text-center p-1" placeholder="-" autocomplete="off">
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <div class="grid grid-cols-2 gap-1">
                                                @foreach($dimensi as $i => $nama)
                                                    @php
                                                        /*
                                                         * [SIKAP-DEFAULT] Default 3 (Mulai Berkembang) —
                                                         * konsisten dengan seluruh sistem; nilai lama
                                                         * tetap dipakai bila ada.
                                                         */
                                                        $val_sikap = max(1, min(5, (int) old('students.' . $s->id . '.d' . $i, $old_score->{"d".$i} ?? 3)));
                                                    @endphp
                                                    <div class="flex flex-col items-center bg-gray-50 p-1 rounded">
                                                        <span class="text-xs text-gray-600 leading-tight mb-0.5">{{ $i }}. {{ $nama }}</span>
                                                        <div class="rating-stars flex space-x-0.5" data-student-id="{{ $s->id }}" data-dim="d{{ $i }}">
                                                            <input type="hidden" name="students[{{ $s->id }}][d{{ $i }}]" value="{{ $val_sikap }}" class="sikap-input">
                                                            @for($j = 1; $j <= 5; $j++)
                                                                <span class="star text-lg cursor-pointer {{ $j <= $val_sikap ? 'text-yellow-400' : 'text-gray-300' }} hover:text-yellow-400" data-val="{{ $j }}" title="Beri {{ $j }} bintang">★</span>
                                                            @endfor
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">
                                            <textarea name="students[{{ $s->id }}][catatan_siswa]" rows="2" maxlength="1000"
                                                      class="w-full border-gray-300 rounded-md shadow-sm text-xs p-2"
                                                      placeholder="">{{ old('students.' . $s->id . '.catatan_siswa', $old_score->catatan_siswa ?? '') }}</textarea>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="p-4 border-t flex justify-end space-x-3">
                        <a href="{{ route('guru.nilai.export', $assignment->id) }}" target="_blank" class="inline-flex items-center justify-center bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 font-bold text-sm shadow-sm transition-colors">
                            Download Nilai Harian (Excel)
                        </a>
                        <button type="submit" id="btn-submit-nilai" class="inline-flex items-center justify-center bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm shadow-sm transition-colors">
                            {{ $tanggal_edit ? 'Simpan Perubahan Pertemuan' : 'Simpan Nilai' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- RIWAYAT INPUT NILAI -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                <div class="bg-gray-50 p-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-800">Riwayat Input Nilai</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Pertemuan Ke-</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Jam / Pukul</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Materi / Tujuan Pembelajaran</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse ($riwayats as $r)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-gray-900">{{ $r->pertemuan_ke }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $r->tanggal }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ trim(($r->jam_ke ? 'Jam ' . $r->jam_ke : '') . (($r->jam_ke && $r->pukul) ? ' · ' : '') . ($r->pukul ?? '')) ?: '-' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $r->materi ?? '(Tidak ada materi)' }}</td>
                                    <td class="px-4 py-3 text-center text-sm font-medium whitespace-nowrap">
                                        <a href="{{ route('guru.nilai', ['id' => $assignment->id, 'tanggal_edit' => $r->tanggal]) }}" class="text-indigo-600 hover:text-indigo-900 font-bold mr-3">Edit</a>
                                        <form action="{{ route('guru.nilai.destroyPertemuan', $assignment->id) }}" method="POST" class="inline" onsubmit="return confirm('Yakin hapus seluruh data pertemuan tanggal {{ $r->tanggal }}?')">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="tanggal" value="{{ $r->tanggal }}">
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-bold">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">Belum ada riwayat pertemuan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {

            /*
            |--------------------------------------------------------------------------
            | 1. LOGIKA KLIK STAR RATING (delegasi — berlaku semua baris)
            |--------------------------------------------------------------------------
            */
            document.body.addEventListener('click', function (e) {
                if (e.target.classList.contains('star')) {
                    const container = e.target.closest('.rating-stars');
                    if (!container) return;

                    const val = parseInt(e.target.dataset.val);
                    const input = container.querySelector('.sikap-input');
                    if (input) input.value = val;

                    container.querySelectorAll('.star').forEach(s => {
                        const sVal = parseInt(s.dataset.val);
                        s.classList.toggle('text-yellow-400', sVal <= val);
                        s.classList.toggle('text-gray-300', sVal > val);
                    });
                }
            });

            /*
            |--------------------------------------------------------------------------
            | 2. MENCEGAH SUBMIT GANDA
            |--------------------------------------------------------------------------
            */
            const formNilai = document.getElementById('form-nilai');
            const btnSubmit = document.getElementById('btn-submit-nilai');

            if (formNilai && btnSubmit) {
                formNilai.addEventListener('submit', function () {
                    btnSubmit.disabled = true;
                    btnSubmit.innerText = 'Menyimpan...';
                    btnSubmit.classList.add('opacity-50', 'cursor-not-allowed');
                });
            }
        });

        /*
        |--------------------------------------------------------------------------
        | 3. SORT TABEL REAL-TIME
        |
        | [SORT-FIX] Dulu fungsi ini dead code (tak ada header yang
        | memanggil). Kini ter-wire ke header No/NISN/Nama/D-F-S.
        | Renumbering di-scope ke tabel ini saja.
        |--------------------------------------------------------------------------
        */
        function sortTable(columnIndex) {
            const table = document.getElementById('tabel-nilai');
            if (!table) return;

            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            const rows = Array.from(tbody.querySelectorAll('tr'));
            const dir = table.dataset.sortDir === 'asc' ? 'desc' : 'asc';
            table.dataset.sortDir = dir;

            rows.sort((a, b) => {
                const aCell = a.cells[columnIndex];
                const bCell = b.cells[columnIndex];
                if (!aCell || !bCell) return 0;

                let aVal = aCell.innerText.trim();
                let bVal = bCell.innerText.trim();

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
                const noCell = row.querySelector('.nomor-urut');
                if (noCell) noCell.innerText = no++;
            });
        }
    </script>
</x-app-layout>