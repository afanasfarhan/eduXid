<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Cetak Naskah Soal
            </h2>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                
                @if(session('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">{{ session('error') }}</div>
                @endif

                @if($errors->any())
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <div class="font-bold mb-1">Data cetak belum lengkap:</div>
                        <ul class="list-disc pl-5 text-sm">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form id="form-cetak-soal" action="{{ route('guru.cetakSoal.pdf') }}" method="POST" target="_blank" x-data="{ selectAll: false }">
                    @csrf
                    
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                        <div>
                            <x-input-label for="judul_ujian" value="Judul Penilaian" class="text-xs font-bold" />
                            <x-text-input name="judul_ujian" type="text" value="{{ old('judul_ujian') }}" class="mt-1 block w-full text-xs py-1.5" placeholder="Cth: Penilaian Tengah Semester" />
                        </div>
                        <div>
                            <x-input-label for="tingkat_kelas" value="Tingkat Kelas" class="text-xs font-bold" />
                            <select name="tingkat_kelas" id="tingkat_kelas" required class="mt-1 block w-full text-xs py-1.5 border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="" disabled @selected(!old('tingkat_kelas'))>-- Pilih Tingkat Kelas --</option>
                                @foreach(($tingkatKelas ?? []) as $tingkat)
                                    <option value="{{ $tingkat }}" @selected((string) old('tingkat_kelas') === (string) $tingkat)>Kelas {{ $tingkat }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-[11px] text-gray-500">Pilih tingkat kelas untuk mengaktifkan soal yang dapat dicetak.</p>
                        </div>
                        <div>
                            <x-input-label for="tanggal" value="Hari/Tanggal" class="text-xs font-bold" />
                            <x-text-input name="tanggal" type="text" value="{{ old('tanggal') }}" class="mt-1 block w-full text-xs py-1.5" placeholder="Rabu, 26 Agustus 2026" />
                        </div>
                        <div>
                            <x-input-label for="waktu" value="Waktu Pelaksanaan" class="text-xs font-bold" />
                            <x-text-input name="waktu" type="text" value="{{ old('waktu') }}" class="mt-1 block w-full text-xs py-1.5" placeholder="Cth: 07.30 - 09.30 WIB" />
                        </div>
                        <div class="flex items-end justify-between gap-4 md:col-span-4 flex-wrap">
                            <div class="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm text-indigo-800">
                                <span class="font-semibold">Jumlah soal yang dipilih:</span>
                                <span id="selected-soal-count" class="inline-flex min-w-8 justify-center rounded-full bg-indigo-600 px-2.5 py-0.5 font-bold text-white">0</span>
                                <span class="text-indigo-600">dari {{ $soals->count() }} soal</span>
                            </div>

                            <button id="btn-cetak-soal-terpilih" type="submit" class="justify-center inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xm text-white tracking-wide hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring ring-indigo-300 disabled:opacity-25 transition ease-in-out duration-150">
                                Cetak Soal Terpilih
                            </button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table id="soalTable" class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-16">
                                        No.
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-24">
                                        Urutan
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-16">
                                        <input type="checkbox"
                                               id="select-all-soal"
                                               x-model="selectAll"
                                               @change="toggleAllSoal(selectAll)"
                                               aria-label="Pilih semua soal"
                                               class="h-5 w-5 text-indigo-600 border-gray-300 rounded">
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-40 cursor-pointer hover:bg-gray-100 select-none" onclick="sortTable(3, this)">
                                        Mata Pelajaran <span class="sort-icon">↕</span>
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-48 cursor-pointer hover:bg-gray-100 select-none" onclick="sortTable(4, this)">
                                        Materi <span class="sort-icon">↕</span>
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-full cursor-pointer hover:bg-gray-100 select-none" onclick="sortTable(5, this)">
                                        Pertanyaan <span class="sort-icon">↕</span>
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-32 cursor-pointer hover:bg-gray-100 select-none" onclick="sortTable(6, this)">
                                        Jenis <span class="sort-icon">↕</span>
                                    </th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20 cursor-pointer hover:bg-gray-100 select-none" onclick="sortTable(7, this)">
                                        Level <span class="sort-icon">↕</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse ($soals as $soal)
                                    <tr data-soal-row data-tingkat-kelas="{{ $soal->tingkat_kelas }}">
                                        <td class="row-number px-4 py-3 text-center text-sm font-semibold text-gray-600 whitespace-nowrap align-top">
                                            {{ $loop->iteration }}
                                        </td>
                                        {{-- Tombol Naik/Turun Urutan --}}
                                        <td class="px-4 py-3 text-center whitespace-nowrap align-top">
                                            <button type="button" onclick="moveRowUp(this)" class="text-blue-500 hover:text-blue-700 text-lg font-bold" title="Naikkan urutan">▲</button>
                                            <button type="button" onclick="moveRowDown(this)" class="text-blue-500 hover:text-blue-700 text-lg font-bold" title="Turunkan urutan">▼</button>
                                        </td>
                                        <td class="px-4 py-3 text-center whitespace-nowrap align-top">
                                            <input type="checkbox"
                                                   name="soal_ids[]"
                                                   value="{{ $soal->id }}"
                                                   onchange="updateSelectedSoalCount()"
                                                   class="h-5 w-5 text-indigo-600 border-gray-300 rounded">
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap w-40">
                                            {{ $soal->subject->nama_mata_pelajaran ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap w-48">
                                            {{ $soal->judul_materi ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 align-top break-words w-full">
                                            {!! \App\Support\QuestionHtmlSanitizer::clean($soal->pertanyaan) !!}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap w-32">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                {{ str_replace('_', ' ', ucfirst($soal->jenis_soal)) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap w-20">
                                            {{ $soal->level_kognitif }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">Belum ada soal di Bank Soal.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <script>
        function getSoalCheckboxes() {
            return Array.from(document.querySelectorAll('input[name="soal_ids[]"]'));
        }

        function updateSelectedSoalCount() {
            const selected = getSoalCheckboxes().filter((checkbox) => checkbox.checked).length;
            const counter = document.getElementById('selected-soal-count');

            if (counter) {
                counter.textContent = String(selected);
            }

            return selected;
        }

        function toggleAllSoal(checked) {
            getSoalCheckboxes().forEach((checkbox) => {
                if (!checkbox.disabled) {
                    checkbox.checked = Boolean(checked);
                }
            });

            updateSelectedSoalCount();
        }

        function applyTingkatKelasFilter() {
            const tingkatSelect = document.getElementById('tingkat_kelas');
            const selectedTingkat = tingkatSelect?.value || '';
            const selectAll = document.getElementById('select-all-soal');

            if (selectAll) {
                selectAll.disabled = selectedTingkat === '';
                selectAll.checked = false;
                selectAll.dispatchEvent(new Event('change', { bubbles: true }));
            }

            document.querySelectorAll('#soalTable tbody tr[data-soal-row]').forEach((row) => {
                const checkbox = row.querySelector('input[name="soal_ids[]"]');
                const rowTingkat = String(row.dataset.tingkatKelas || '');
                const matches = selectedTingkat !== '' && rowTingkat === selectedTingkat;

                row.style.display = selectedTingkat === '' || matches ? '' : 'none';

                if (checkbox) {
                    checkbox.disabled = !matches;
                    if (!matches) {
                        checkbox.checked = false;
                    }
                }
            });

            updateSelectedSoalCount();
            renumberSoalRows();
        }

        function renumberSoalRows() {
            let visibleIndex = 0;

            document.querySelectorAll('#soalTable tbody tr[data-soal-row]').forEach((row) => {
                const numberCell = row.querySelector('.row-number');

                if (row.style.display === 'none') {
                    return;
                }

                visibleIndex += 1;

                if (numberCell) {
                    numberCell.textContent = String(visibleIndex);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const tingkatKelas = document.getElementById('tingkat_kelas');

            if (tingkatKelas) {
                tingkatKelas.addEventListener('change', applyTingkatKelasFilter);
            }

            applyTingkatKelasFilter();

            const form = document.getElementById('form-cetak-soal');
            if (form) {
                form.addEventListener('submit', (event) => {
                    const selected = updateSelectedSoalCount();
                    const tingkatKelas = document.getElementById('tingkat_kelas');

                    if (selected < 1) {
                        event.preventDefault();
                        alert('Pilih minimal satu soal yang akan dicetak.');
                        return;
                    }

                    if (!tingkatKelas || !tingkatKelas.value) {
                        event.preventDefault();
                        alert('Pilih tingkat kelas terlebih dahulu.');
                        tingkatKelas?.focus();
                        return;
                    }

                    const invalidSelection = getSoalCheckboxes().some((checkbox) => {
                        if (!checkbox.checked) {
                            return false;
                        }

                        return String(checkbox.closest('tr')?.dataset.tingkatKelas || '') !== String(tingkatKelas.value);
                    });

                    if (invalidSelection) {
                        event.preventDefault();
                        alert('Semua soal terpilih harus berasal dari tingkat kelas yang sama. Pilih ulang soal.');
                    }
                });
            }
        });

        // 1. Fungsi Sort Alphabetical
        function sortTable(columnIndex, headerEl) {
            const table = document.getElementById("soalTable");
            let switching = true;
            let dir = headerEl.classList.contains('sort-asc') ? 'desc' : 'asc';
            
            table.querySelectorAll('th').forEach(th => {
                th.classList.remove('sort-asc', 'sort-desc');
                let icon = th.querySelector('.sort-icon');
                if(icon) icon.textContent = '↕';
            });

            if (dir === 'asc') {
                headerEl.classList.add('sort-asc');
                headerEl.querySelector('.sort-icon').textContent = '↑';
            } else {
                headerEl.classList.add('sort-desc');
                headerEl.querySelector('.sort-icon').textContent = '↓';
            }

            while (switching) {
                switching = false;
                const rows = table.rows;
                
                for (let i = 1; i < (rows.length - 1); i++) {
                    let shouldSwitch = false;
                    let x = rows[i].getElementsByTagName("TD")[columnIndex];
                    let y = rows[i + 1].getElementsByTagName("TD")[columnIndex];
                    
                    if(!x || !y) break;

                    let xVal = x.textContent.toLowerCase().trim();
                    let yVal = y.textContent.toLowerCase().trim();
                    
                    if (dir === 'asc') {
                        if (xVal > yVal) { shouldSwitch = true; break; }
                    } else {
                        if (xVal < yVal) { shouldSwitch = true; break; }
                    }
                }
                
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                }
            }

            renumberSoalRows();
        }

        // 2. Fungsi Pindah Baris Manual (Naik/Turun)
        function moveRowUp(button) {
            const row = button.closest('tr');
            let prevRow = row?.previousElementSibling;

            while (prevRow && (!prevRow.hasAttribute('data-soal-row') || prevRow.style.display === 'none')) {
                prevRow = prevRow.previousElementSibling;
            }

            if (row && prevRow) {
                row.parentNode.insertBefore(row, prevRow);
                renumberSoalRows();
            }
        }

        function moveRowDown(button) {
            const row = button.closest('tr');
            let nextRow = row?.nextElementSibling;

            while (nextRow && (!nextRow.hasAttribute('data-soal-row') || nextRow.style.display === 'none')) {
                nextRow = nextRow.nextElementSibling;
            }

            if (row && nextRow) {
                row.parentNode.insertBefore(nextRow, row);
                renumberSoalRows();
            }
        }
    </script>
</x-app-layout>