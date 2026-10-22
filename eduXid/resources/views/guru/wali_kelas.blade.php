<x-app-layout>

    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Wali Kelas: {{ $rombel->nama ?? 'Belum Ada Penugasan' }}
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Catatan perkembangan murid
                </p>
            </div>
            @if(isset($viewingSemester) && isset($semesterAktif) && $viewingSemester !== $semesterAktif)
                <a href="{{ url('/wali-kelas') }}"
                   class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 border border-amber-300 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-amber-200 transition-colors">
                    📅 Semester Lampau ({{ $viewingSemester }}) — klik untuk kembali ke semester aktif
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="font-bold mb-2">Terjadi kesalahan:</div>
                    <ul class="list-disc list-inside text-sm">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($rombel)

                {{-- PANDUAN --}}
                <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-800 p-4 mb-6 rounded-r-lg">
                    <div class="font-bold mb-2">Catatan Perkembangan Murid</div>
                    <p class="text-sm leading-relaxed mb-3">
                        Catatan dibuat dalam satu paragraf yang menggambarkan perkembangan kemampuan murid
                        secara keseluruhan. Deskripsi dapat memuat kemampuan yang sudah berkembang, hal yang
                        masih perlu ditingkatkan, serta mata pelajaran yang menunjukkan hasil paling menonjol
                        berdasarkan nilai akhir.
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2 text-xs">
                        <div class="bg-white rounded p-2 border">
                            <span class="text-yellow-400 text-lg">★</span>
                            <strong>1</strong>
                            <div>Perlu Intervensi</div>
                        </div>
                        <div class="bg-white rounded p-2 border">
                            <span class="text-yellow-400 text-lg">★★</span>
                            <strong>2</strong>
                            <div>Sedang Berkembang</div>
                        </div>
                        <div class="bg-white rounded p-2 border">
                            <span class="text-yellow-400 text-lg">★★★</span>
                            <strong>3</strong>
                            <div>Mulai Berkembang</div>
                        </div>
                        <div class="bg-white rounded p-2 border">
                            <span class="text-yellow-400 text-lg">★★★★</span>
                            <strong>4</strong>
                            <div>Berkembang Sesuai Harapan</div>
                        </div>
                        <div class="bg-white rounded p-2 border">
                            <span class="text-yellow-400 text-lg">★★★★★</span>
                            <strong>5</strong>
                            <div>Sangat Berkembang</div>
                        </div>
                    </div>
                </div>

                {{-- FORM UTAMA --}}
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <form action="{{ route('guru.waliKelas.store') }}" method="POST" id="form-wali-kelas">
                        @csrf

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200" id="tabel-wali">

                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 w-12" onclick="sortTable(0)">No</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 min-w-[220px]" onclick="sortTable(1)">Nama Murid</th>
                                        <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20">Sakit</th>
                                        <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20">Izin</th>
                                        <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20">Alpa</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase min-w-[340px]">Perkembangan</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase min-w-[650px]">Catatan Perkembangan</th>
                                    </tr>
                                </thead>

                                <tbody class="bg-white divide-y divide-gray-200">
                                    @foreach($students as $s)

                                        @php
                                            $dimensions = [
                                                'd1' => 'Keimanan',
                                                'd2' => 'Kewargaan',
                                                'd3' => 'Penalaran',
                                                'd4' => 'Kreativitas',
                                                'd5' => 'Kolaborasi',
                                                'd6' => 'Kemandirian',
                                                'd7' => 'Kesehatan',
                                                'd8' => 'Komunikasi',
                                            ];

                                            $ratings = [
                                                'd1' => (int) ($s->d1 ?? 3),
                                                'd2' => (int) ($s->d2 ?? 3),
                                                'd3' => (int) ($s->d3 ?? 3),
                                                'd4' => (int) ($s->d4 ?? 3),
                                                'd5' => (int) ($s->d5 ?? 3),
                                                'd6' => (int) ($s->d6 ?? 3),
                                                'd7' => (int) ($s->d7 ?? 3),
                                                'd8' => (int) ($s->d8 ?? 3),
                                            ];

                                            $averageRating = $s->average_rating ?? (array_sum($ratings) / 8);
                                            $overallRating = max(1, min(5, (int) ($s->overall_rating ?? round($averageRating))));
                                            $predicate = $s->predicate ?? match($overallRating) {
                                                1 => 'Perlu Intervensi',
                                                2 => 'Sedang Berkembang',
                                                3 => 'Mulai Berkembang',
                                                4 => 'Berkembang Sesuai Harapan',
                                                5 => 'Sangat Berkembang',
                                                default => 'Mulai Berkembang',
                                            };
                                        @endphp

                                        <tr class="hover:bg-gray-50 transition">

                                            {{-- NO --}}
                                            <td class="px-2 py-3 text-center text-sm text-gray-500 nomor-urut">
                                                {{ $loop->iteration }}
                                            </td>

                                            {{-- NAMA SISWA — tanpa "Ananda"; nama asli ditampilkan --}}
                                            <td class="px-4 py-3 align-top">
                                                <div class="text-sm font-semibold text-gray-900">
                                                    {{ $s->nama }}
                                                </div>
                                                @if(!empty($s->nisn))
                                                    <div class="text-xs text-gray-400 mt-1">
                                                        NISN: {{ $s->nisn }}
                                                    </div>
                                                @endif
                                                <input type="hidden" name="students[{{ $s->id }}][enrollment_id]" value="{{ $s->enrollment_id }}">
                                            </td>

                                            {{-- SAKIT --}}
                                            <td class="px-2 py-3 text-center align-top">
                                                <input type="number" name="students[{{ $s->id }}][rapor_sakit]" value="{{ $s->rapor_sakit ?? 0 }}" min="0" class="w-16 border-gray-300 rounded-md shadow-sm text-sm text-center p-1">
                                            </td>

                                            {{-- IZIN --}}
                                            <td class="px-2 py-3 text-center align-top">
                                                <input type="number" name="students[{{ $s->id }}][rapor_izin]" value="{{ $s->rapor_izin ?? 0 }}" min="0" class="w-16 border-gray-300 rounded-md shadow-sm text-sm text-center p-1">
                                            </td>

                                            {{-- ALPA --}}
                                            <td class="px-2 py-3 text-center align-top">
                                                <input type="number" name="students[{{ $s->id }}][rapor_alpa]" value="{{ $s->rapor_alpa ?? 0 }}" min="0" class="w-16 border-gray-300 rounded-md shadow-sm text-sm text-center p-1">
                                            </td>

                                            {{-- PERKEMBANGAN --}}
                                            <td class="px-4 py-3 align-top">
                                                <div class="space-y-2">
                                                    @foreach($dimensions as $key => $label)
                                                        <div class="flex items-center justify-between gap-2">
                                                            <div class="text-xs font-medium text-gray-700 min-w-[110px]">{{ $label }}</div>
                                                            <div class="flex items-center gap-0.5">
                                                                @for($star = 1; $star <= 5; $star++)
                                                                    <span class="text-base leading-none {{ $star <= $ratings[$key] ? 'text-yellow-400' : 'text-gray-300' }}">★</span>
                                                                @endfor
                                                            </div>
                                                            <div class="text-xs font-semibold text-gray-500 w-5 text-right">{{ $ratings[$key] }}</div>
                                                        </div>
                                                    @endforeach
                                                </div>

                                                {{-- RATING KESELURUHAN --}}
                                                <div class="mt-3 pt-3 border-t border-gray-200">
                                                    <div class="flex items-center justify-between">
                                                        <div>
                                                            <div class="text-xs text-gray-500">Rating keseluruhan</div>
                                                            <div class="flex items-center gap-1 mt-1">
                                                                @for($star = 1; $star <= 5; $star++)
                                                                    <span class="text-lg leading-none {{ $star <= $overallRating ? 'text-yellow-400' : 'text-gray-300' }}">★</span>
                                                                @endfor
                                                            </div>
                                                        </div>
                                                        <div class="text-right">
                                                            <div class="text-lg font-bold text-gray-800">{{ number_format($averageRating, 2) }}</div>
                                                            <div class="text-xs font-semibold text-indigo-600">{{ $predicate }}</div>
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- HIDDEN RATING --}}
                                                @foreach($ratings as $key => $value)
                                                    <input type="hidden" name="students[{{ $s->id }}][{{ $key }}]" value="{{ $value }}">
                                                @endforeach
                                            </td>

                                            {{-- CATATAN PERKEMBANGAN --}}
                                            <td class="px-4 py-3 align-top">
                                                <div class="space-y-2">
                                                    <textarea
                                                        id="catatan-{{ $s->id }}"
                                                        name="students[{{ $s->id }}][catatan_wali]"
                                                        rows="14"
                                                        class="w-full border-gray-300 rounded-md shadow-sm text-sm leading-relaxed p-3 text-justify focus:border-indigo-500 focus:ring-indigo-500"
                                                        placeholder="Tuliskan satu paragraf yang menggambarkan perkembangan murid secara keseluruhan."
                                                    >{{ $s->catatan_wali ?? '' }}</textarea>

                                                    <div class="flex flex-wrap gap-2">
                                                        {{-- DESKRIPSI OTOMATIS: isi textarea, belum menyimpan --}}
                                                        <button
                                                            type="button"
                                                            class="btn-deskripsi-otomatis inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-semibold text-sm shadow-sm transition"
                                                            data-target="catatan-{{ $s->id }}"
                                                            data-description="{{ $s->auto_description }}"
                                                        >
                                                            ✨ Deskripsi Otomatis
                                                        </button>

                                                        {{-- [BARU] HAPUS DESKRIPSI: kosongkan textarea siswa ini.
                                                             Client-side saja (belum menyimpan) —
                                                             pola sama dengan Deskripsi Otomatis;
                                                             user klik "Simpan Data" untuk menyimpan. --}}
                                                        <button
                                                            type="button"
                                                            class="btn-hapus-deskripsi inline-flex items-center justify-center gap-2 bg-red-50 text-red-600 border border-red-200 px-4 py-2 rounded-md hover:bg-red-100 font-semibold text-sm transition"
                                                            data-target="catatan-{{ $s->id }}"
                                                        >
                                                            🗑️ Hapus Deskripsi
                                                        </button>
                                                    </div>

                                                    <div class="text-xs text-gray-400">
                                                        ✨ = ganti catatan manual dengan deskripsi otomatis.
                                                        🗑️ = kosongkan catatan murid ini.
                                                        Keduanya baru tersimpan setelah menekan <b>Simpan Data</b>.
                                                    </div>
                                                </div>
                                            </td>

                                        </tr>

                                    @endforeach
                                </tbody>

                            </table>
                        </div>

                        {{-- TOMBOL BAWAH --}}
                        <div class="p-4 border-t bg-gray-50 flex flex-wrap gap-3 justify-end items-center">

                            {{-- [BARU] HAPUS DESKRIPSI SEMUA — langsung ke database
                                 (route dari WaliKelasController::destroyDescriptions) --}}
                            @if(Route::has('guru.waliKelas.destroyDescriptions'))
                                <button
                                    type="submit"
                                    formaction="{{ route('guru.waliKelas.destroyDescriptions') }}"
                                    formmethod="POST"
                                    id="btn-hapus-semua"
                                    onclick="return confirm('Hapus SEMUA deskripsi/catatan wali kelas pada rombel ini? Tindakan ini langsung tersimpan dan tidak dapat dibatalkan.')"
                                    class="bg-red-50 text-red-600 border border-red-300 px-6 py-2.5 rounded-md hover:bg-red-100 font-bold text-sm transition"
                                >
                                    🗑️ Hapus Deskripsi Semua
                                </button>
                            @endif

                            {{-- DESKRIPSI OTOMATIS SEMUA --}}
                            <button
                                type="submit"
                                formaction="{{ route('guru.waliKelas.generateDescriptions') }}"
                                formmethod="POST"
                                id="btn-deskripsi-semua"
                                class="bg-blue-600 text-white px-6 py-2.5 rounded-md hover:bg-blue-700 font-bold text-sm shadow-sm"
                            >
                                ✨ Deskripsi Otomatis Semua
                            </button>

                            {{-- SIMPAN --}}
                            <button
                                type="submit"
                                id="btn-simpan"
                                class="bg-indigo-600 text-white px-6 py-2.5 rounded-md hover:bg-indigo-700 font-bold text-sm shadow-sm"
                            >
                                💾 Simpan Data
                            </button>

                        </div>

                    </form>
                </div>

            @else

                {{-- TIDAK ADA KELAS --}}
                <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                    <div class="text-6xl mb-4">⚠️</div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Anda Bukan Wali Kelas</h3>
                    <p class="text-gray-500">
                        Akun Anda tidak terdaftar sebagai Wali Kelas pada kelas reguler mana pun
                        untuk semester ini. Jika Anda merasa ini adalah kesalahan,
                        silakan hubungi Admin sekolah.
                    </p>
                </div>

            @endif

        </div>
    </div>

    {{-- =============================================================
         JAVASCRIPT
    ============================================================== --}}
    <script>

        /*
        |--------------------------------------------------------------------------
        | SORT TABLE
        |--------------------------------------------------------------------------
        */
        function sortTable(columnIndex) {
            const table = document.getElementById('tabel-wali');
            if (!table) return;

            const tbody = table.querySelector('tbody');
            if (!tbody) return;

            const rows = Array.from(tbody.querySelectorAll('tr'));
            const currentDirection = table.dataset.sortDir === 'asc' ? 'desc' : 'asc';
            table.dataset.sortDir = currentDirection;

            rows.sort(function (a, b) {
                const aCell = a.cells[columnIndex];
                const bCell = b.cells[columnIndex];
                if (!aCell || !bCell) return 0;

                const aVal = aCell.innerText.trim().toLowerCase();
                const bVal = bCell.innerText.trim().toLowerCase();

                if (aVal < bVal) return currentDirection === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentDirection === 'asc' ? 1 : -1;
                return 0;
            });

            let nomor = 1;
            rows.forEach(function (row) {
                tbody.appendChild(row);
                const nomorCell = row.querySelector('.nomor-urut');
                if (nomorCell) nomorCell.innerText = nomor++;
            });
        }

        /*
        |--------------------------------------------------------------------------
        | FEEDBACK TOMBOL SEMENTARA
        |--------------------------------------------------------------------------
        */
        function flashButton(button, okText) {
            const originalText = button.innerHTML;
            button.innerHTML = okText;
            button.disabled = true;

            setTimeout(function () {
                button.innerHTML = originalText;
                button.disabled = false;
            }, 1800);
        }

        /*
        |--------------------------------------------------------------------------
        | DESKRIPSI OTOMATIS PER SISWA
        |--------------------------------------------------------------------------
        |
        | TIDAK langsung menyimpan ke database:
        | 1. ambil narasi otomatis
        | 2. masukkan ke textarea
        | 3. wali kelas periksa/edit
        | 4. klik "Simpan Data"
        |
        */
        document.querySelectorAll('.btn-deskripsi-otomatis').forEach(function (button) {
            button.addEventListener('click', function () {
                const textarea = document.getElementById(button.dataset.target);
                if (!textarea) return;

                const description = button.dataset.description;
                if (!description) {
                    alert('Deskripsi otomatis belum dapat dibuat karena data penilaian belum tersedia.');
                    return;
                }

                textarea.value = description;
                textarea.focus();
                textarea.selectionStart = textarea.value.length;
                textarea.selectionEnd = textarea.value.length;

                flashButton(button, '✓ Deskripsi Dimasukkan');
            });
        });

        /*
        |--------------------------------------------------------------------------
        | [BARU] HAPUS DESKRIPSI PER SISWA
        |--------------------------------------------------------------------------
        |
        | Mengosongkan textarea siswa (client-side, belum menyimpan).
        | Konfirmasi dulu agar isi manual tidak hilang tanpa sengaja.
        | Perubahan baru tersimpan setelah "Simpan Data".
        |
        */
        document.querySelectorAll('.btn-hapus-deskripsi').forEach(function (button) {
            button.addEventListener('click', function () {
                const textarea = document.getElementById(button.dataset.target);
                if (!textarea) return;

                if (textarea.value.trim() === '') {
                    alert('Catatan murid ini sudah kosong.');
                    return;
                }

                if (!confirm('Hapus isi catatan perkembangan murid ini?\n\nIsi saat ini akan dikosongkan dari form.\nTekan "Simpan Data" untuk mempermanenkannya.')) {
                    return;
                }

                textarea.value = '';
                textarea.focus();

                flashButton(button, '✓ Dikosongkan');
            });
        });

        /*
        |--------------------------------------------------------------------------
        | CEGAH KLIK GANDA SAAT SUBMIT
        |--------------------------------------------------------------------------
        */
        document.getElementById('form-wali-kelas')?.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            if (!submitter) return;

            // Tombol per-siswa (auto/hapus) tidak submit form — jangan disentuh.
            if (submitter.classList.contains('btn-deskripsi-otomatis')
                || submitter.classList.contains('btn-hapus-deskripsi')) {
                return;
            }

            if (submitter.id === 'btn-hapus-semua') {
                submitter.disabled = true;
                submitter.innerText = '⏳ Menghapus Deskripsi...';
                return;
            }

            if (submitter.id === 'btn-deskripsi-semua') {
                submitter.disabled = true;
                submitter.innerText = '⏳ Membuat Deskripsi...';
                return;
            }

            if (submitter.id === 'btn-simpan') {
                submitter.disabled = true;
                submitter.innerText = '⏳ Menyimpan...';
            }
        });

    </script>


{{-- EDUXID_STATUS_AKHIR_SEMESTER_V1 --}}
<script>
document.addEventListener('DOMContentLoaded', async function () {
    'use strict';

    const dataUrl = @json(route('guru.waliKelas.statusData'));
    const storeUrl = @json(route('guru.waliKelas.statusStore'));
    const csrf = @json(csrf_token());

    function numericCandidates(textarea) {
        const ids = new Set();

        const add = function (value) {
            const text = String(value || '').trim();
            if (/^\d+$/.test(text)) {
                ids.add(text);
            }
        };

        const name = String(textarea.getAttribute('name') || '');
        const matches = name.match(/\[(\d+)\]/g) || [];
        matches.forEach(function (token) {
            add(token.replace(/\D/g, ''));
        });

        let container = textarea.closest('tr');
        if (!container) {
            container = textarea.closest('[data-student-id], [data-enrollment-id]');
        }
        if (!container) {
            container = textarea.parentElement;
        }

        if (container) {
            add(container.dataset ? container.dataset.studentId : '');
            add(container.dataset ? container.dataset.enrollmentId : '');

            container.querySelectorAll('input[type="hidden"], input[type="number"]').forEach(function (input) {
                const inputName = String(input.name || '').toLowerCase();

                if (
                    inputName.includes('student_id') ||
                    inputName.includes('enrollment_id') ||
                    inputName.includes('siswa_id')
                ) {
                    add(input.value);
                }
            });
        }

        return Array.from(ids);
    }

    function buildLookup(rows) {
        const byEnrollment = new Map();
        const byStudent = new Map();

        rows.forEach(function (row) {
            byEnrollment.set(String(row.enrollment_id), row);
            byStudent.set(String(row.student_id), row);
        });

        return { byEnrollment, byStudent };
    }

    function resolveRow(textarea, lookup) {
        const ids = numericCandidates(textarea);

        for (const id of ids) {
            if (lookup.byEnrollment.has(id)) {
                return lookup.byEnrollment.get(id);
            }
        }

        for (const id of ids) {
            if (lookup.byStudent.has(id)) {
                return lookup.byStudent.get(id);
            }
        }

        return null;
    }

    function attachStatus(textarea, row) {
        if (textarea.dataset.eduxidStatusAttached === '1') {
            return;
        }

        textarea.dataset.eduxidStatusAttached = '1';

        const wrap = document.createElement('div');
        wrap.style.display = 'grid';
        wrap.style.gridTemplateColumns = 'minmax(0, 1fr) minmax(190px, 230px)';
        wrap.style.gap = '10px';
        wrap.style.alignItems = 'start';
        wrap.className = 'eduxid-catatan-status-wrap';

        const parent = textarea.parentNode;
        parent.insertBefore(wrap, textarea);
        wrap.appendChild(textarea);

        const panel = document.createElement('div');
        panel.className = 'rounded-lg border border-gray-200 bg-gray-50 p-2';

        const label = document.createElement('label');
        label.className = 'mb-1 block text-xs font-semibold text-gray-700';
        label.textContent = row.is_final_level
            ? 'Status Kelulusan'
            : 'Status Kenaikan Kelas';

        const select = document.createElement('select');
        select.className = 'w-full rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500';
        select.dataset.enrollmentId = String(row.enrollment_id);

        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '-- Belum ditentukan --';
        select.appendChild(empty);

        Object.entries(row.options || {}).forEach(function ([value, text]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = text;
            select.appendChild(option);
        });

        select.value = row.status || '';

        const note = document.createElement('div');
        note.className = 'mt-1 text-[11px] leading-tight text-gray-500';
        note.textContent = row.is_final_level
            ? 'Kelas tingkat akhir: Lulus / Tidak Lulus'
            : 'Kelas non-akhir: Naik Kelas / Tidak Naik Kelas';

        const state = document.createElement('div');
        state.className = 'mt-1 min-h-[16px] text-[11px] text-gray-500';

        select.addEventListener('change', async function () {
            select.disabled = true;
            state.textContent = 'Menyimpan...';

            try {
                const response = await fetch(storeUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        enrollment_id: Number(row.enrollment_id),
                        status: select.value || null,
                    }),
                });

                const payload = await response.json().catch(function () {
                    return {};
                });

                if (!response.ok) {
                    throw new Error(
                        payload.message ||
                        'Status akhir semester gagal disimpan.'
                    );
                }

                row.status = payload.status || null;
                state.textContent = 'Tersimpan';
                state.className = 'mt-1 min-h-[16px] text-[11px] text-emerald-600';
            } catch (error) {
                select.value = row.status || '';
                state.textContent = error.message || 'Gagal menyimpan';
                state.className = 'mt-1 min-h-[16px] text-[11px] text-red-600';
            } finally {
                select.disabled = false;
            }
        });

        panel.appendChild(label);
        panel.appendChild(select);
        panel.appendChild(note);
        panel.appendChild(state);
        wrap.appendChild(panel);
    }

    try {
        const response = await fetch(dataUrl, {
            headers: {
                'Accept': 'application/json',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();

        if (!payload.enabled || !Array.isArray(payload.rows)) {
            return;
        }

        const lookup = buildLookup(payload.rows);

        document.querySelectorAll('textarea').forEach(function (textarea) {
            const name = String(textarea.getAttribute('name') || '').toLowerCase();
            const parentText = String(
                textarea.closest('td, div')?.textContent || ''
            ).toLowerCase();

            if (
                !name.includes('catatan') &&
                !parentText.includes('catatan wali')
            ) {
                return;
            }

            const row = resolveRow(textarea, lookup);

            if (row) {
                attachStatus(textarea, row);
            }
        });
    } catch (error) {
        console.warn('[eduXid] Status akhir semester tidak dapat dimuat.', error);
    }
});
</script>
</x-app-layout>