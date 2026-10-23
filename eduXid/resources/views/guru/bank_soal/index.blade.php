<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Bank Soal Guru
                </h2>
                <p class="text-xs text-gray-500 mt-1">
                    Soal dikelompokkan berdasarkan mata pelajaran dan tingkat kelas yang Anda ampu.
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    Gambar Excel: gunakan Insert → Pictures → Place over Cells; letakkan gambar di kolom P pada baris yang sama,
                    lalu Upload Soal (.xlsx). Maksimal 200 KB per gambar.
                </p>
            </div>

            {{-- Tiga aksi utama tetap satu baris dan rata kanan. Pada HP bisa digeser horizontal. --}}
            <div class="w-full lg:w-auto lg:shrink-0 overflow-x-auto pb-1">
                <div class="flex flex-nowrap items-center justify-end gap-2 min-w-max">
                    <a href="{{ route('guru.bankSoal.create') }}"
                       class="inline-flex shrink-0 whitespace-nowrap items-center justify-center h-10 bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm transition-colors">
                        + Tambah Soal
                    </a>

                    <form action="{{ route('guru.bankSoal.import') }}" method="POST" enctype="multipart/form-data" id="formImportSoal" class="shrink-0">
                        @csrf
                        <input type="file" name="excel_file" id="excel_file_input" accept=".xlsx,.xls,.csv" class="hidden" onchange="validateAndSubmit(this)">
                        <label for="excel_file_input"
                               class="cursor-pointer inline-flex shrink-0 whitespace-nowrap items-center justify-center h-10 bg-yellow-500 text-white px-4 py-2 rounded-md hover:bg-yellow-600 font-bold text-sm transition-colors">
                            📤 Upload Soal
                        </label>
                    </form>

                    <a href="{{ route('guru.bankSoal.export') }}" target="_blank"
                       class="inline-flex shrink-0 whitespace-nowrap items-center justify-center h-10 bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 font-bold text-sm transition-colors">
                        📥 Download Soal
                    </a>
                </div>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="bg-white border border-indigo-100 shadow-sm rounded-xl p-5 mb-5">
                <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
                    <div><h3 class="text-base font-bold text-gray-800">Impor Soal Word · AI Lokal</h3>
                    <p class="text-xs text-gray-500 mt-1">DOCX format bebas → pratinjau soal → guru isi kunci → simpan. Tabel, grafik, dan penomoran otomatis Word didukung.</p></div>
                    <span class="px-3 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-semibold">Offline-first di laptop</span>
                </div>
                <form action="{{ route('guru.bankSoal.import') }}" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    @csrf
                    <input type="hidden" name="word_mode" value="preview">
                    <label class="block text-xs font-semibold text-gray-700">Dokumen Word
                        <input type="file" name="word_file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required class="mt-1 block w-full text-sm rounded-md border border-gray-300 p-2">
                    </label>
                    <label class="block text-xs font-semibold text-gray-700">Mata Pelajaran
                        <select name="subject_id" required class="mt-1 block w-full text-sm rounded-md border-gray-300">
                            <option value="">— Pilih —</option>
                            @foreach($wordSubjectNames as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block text-xs font-semibold text-gray-700">Tingkat Kelas
                        <select name="tingkat_kelas" required class="mt-1 block w-full text-sm rounded-md border-gray-300">
                            <option value="">— Pilih —</option>
                            @foreach($tingkatDiajar as $grade)<option value="{{ $grade }}">{{ $grade }}</option>@endforeach
                        </select>
                    </label>
                    <div class="flex flex-col gap-2">
                        <label class="inline-flex items-center gap-2 text-xs text-gray-600"><input type="checkbox" name="ai_mode" value="on" checked> Analisis AI Lokal Qwen (jika tersedia)</label>
                        <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 text-sm font-semibold">Pratinjau Impor Word</button>
                    </div>
                </form>
                <p class="text-xs text-amber-700 mt-3">AI tidak mengarang kunci. Format yang gagal dikenali akan diberi peringatan; belum ada data tersimpan sebelum Anda menekan Simpan Soal Terpilih.</p>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-4 mb-5">
                <form method="GET" action="{{ route('guru.bankSoal') }}" class="flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
                    <div class="w-full sm:w-56">
                        <x-input-label for="filter_tingkat_kelas" value="Filter Tingkat Kelas" class="text-xs font-bold" />
                        <select name="tingkat_kelas" id="filter_tingkat_kelas"
                                class="mt-1 block w-full text-sm border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Semua Tingkat</option>
                            @foreach($tingkatDiajar as $tingkat)
                                <option value="{{ $tingkat }}" @selected((string) $filterTingkat === (string) $tingkat)>
                                    Kelas {{ $tingkat }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit"
                            class="h-10 px-4 rounded-md bg-gray-800 text-white text-sm font-bold hover:bg-gray-900">
                        Filter
                    </button>

                    @if($filterTingkat !== null)
                        <a href="{{ route('guru.bankSoal') }}"
                           class="h-10 inline-flex items-center px-4 rounded-md border border-gray-300 text-gray-700 text-sm font-bold hover:bg-gray-50">
                            Reset
                        </a>
                    @endif
                </form>

                @if($soals->contains(fn ($soal) => empty($soal->tingkat_kelas)))
                    <p class="text-xs text-amber-600 mt-3">
                        ⚠️ Ada soal lama yang belum memiliki tingkat kelas. Buka <b>Edit</b> pada soal tersebut untuk menetapkannya.
                    </p>
                @endif
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase w-12">
                                <input type="checkbox" id="pilihSemuaSoal" aria-label="Pilih semua soal yang tampil"
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            </th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-16">No.</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-40">Mata Pelajaran</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-28">Kelas</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-48">Materi / Submateri</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase min-w-[320px]">Pertanyaan</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-32">Jenis</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20">Level</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase w-20">Bobot</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase min-w-[210px]">Aksi</th>
                        </tr>
                    </thead>

                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($soals as $soal)
                            <tr>
                                <td class="px-3 py-3 text-center align-top">
                                    <input type="checkbox" name="soal_ids[]" value="{{ $soal->id }}" form="formHapusTerpilih"
                                           class="pilih-soal rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                           aria-label="Pilih soal nomor {{ $loop->iteration }}">
                                </td>
                                <td class="px-4 py-3 text-sm font-semibold text-gray-600 align-top whitespace-nowrap text-center">
                                    {{ $loop->iteration }}
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap">
                                    {{ $soal->subject->nama_mata_pelajaran ?? '-' }}
                                </td>

                                <td class="px-4 py-3 text-sm align-top whitespace-nowrap text-center">
                                    @if(!empty($soal->tingkat_kelas))
                                        <span class="inline-flex px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-200 text-xs font-bold">
                                            Kelas {{ $soal->tingkat_kelas }}
                                        </span>
                                    @else
                                        <span class="inline-flex px-2 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-[11px] font-bold">
                                            Belum ditetapkan
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-500 align-top">
                                    {{ $soal->judul_materi ?? '-' }}
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-900 align-top break-words">
                                    @if(!empty($soal->tka_group_code))
                                        <div class="mb-1 inline-flex items-center rounded bg-indigo-50 border border-indigo-200 text-indigo-700 px-2 py-1 text-[11px] font-semibold">
                                            {{ $soal->tka_category ? 'TKA Kategori' : 'TKA Grup' }} · {{ $soal->tka_group_code }}
                                            @if($soal->tka_category) · Pernyataan {{ $soal->tka_statement_order ?? '-' }} @endif
                                        </div>
                                    @endif
                                    <div class="prose prose-sm max-w-none bank-question-content">
                                        {!! \App\Support\QuestionHtmlSanitizer::clean($soal->pertanyaan) !!}
                                    </div>
                                    @if(!empty($soal->gambar_soal))
                                        <img src="{{ $soal->gambar_soal }}" alt="Gambar pendukung soal"
                                             loading="lazy" class="mt-2 max-h-44 max-w-full object-contain rounded border">
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        {{ str_replace('_', ' ', ucfirst($soal->jenis_soal)) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap text-center">
                                    {{ $soal->level_kognitif }}
                                </td>

                                <td class="px-4 py-3 text-sm text-gray-500 align-top whitespace-nowrap text-center">
                                    {{ $soal->bobot_nilai }}
                                </td>

                                <td class="px-4 py-3 text-sm font-medium align-top whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="{{ route('guru.bankSoal.edit', $soal->id) }}"
                                           class="text-indigo-600 hover:text-indigo-900 font-semibold">Edit</a>
                                        <form action="{{ route('guru.bankSoal.destroy', $soal->id) }}" method="POST"
                                              class="inline-flex"
                                              onsubmit="return confirm('Hapus soal ini dari Bank Soal?\n\nSoal yang sudah digunakan dalam Paket Ujian, jawaban siswa, atau analisis butir tidak dapat dihapus.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" aria-label="Hapus soal nomor {{ $loop->iteration }}"
                                                    class="text-red-600 hover:text-red-800 font-semibold hover:underline">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-6 text-center text-sm text-gray-500">
                                    @if($filterTingkat !== null)
                                        Belum ada soal untuk Kelas {{ $filterTingkat }}.
                                    @else
                                        Belum ada soal di Bank Soal. Klik "+ Tambah Soal" atau "Upload Soal".
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-wrap justify-end items-center gap-3">
                <span id="jumlahSoalDipilih" class="text-sm text-gray-500" aria-live="polite">0 soal dipilih</span>
                <form id="formHapusTerpilih" method="POST" action="{{ route('guru.bankSoal.destroySelected') }}"
                      onsubmit="return konfirmasiHapusTerpilih(this)">
                    @csrf
                    @method('DELETE')
                    <button id="tombolHapusTerpilih" type="submit" disabled
                            class="px-4 py-2 rounded-md text-white font-bold text-sm bg-red-700 hover:bg-red-800 disabled:opacity-40 disabled:cursor-not-allowed">
                        🗑 Hapus Soal Terpilih
                    </button>
                </form>
            </div>
        </div>
    </div>

    <style>
        .bank-question-content img {
            max-width: 100% !important;
            max-height: 220px !important;
            width: auto !important;
            height: auto !important;
            object-fit: contain;
        }
    </style>

    <script>
        function konfirmasiHapusTerpilih(form) {
            const chosen = document.querySelectorAll('.pilih-soal:checked').length;
            if (chosen === 0) {
                alert('Centang soal yang ingin dihapus terlebih dahulu.');
                return false;
            }
            return confirm('Hapus ' + chosen + ' soal terpilih?\n\nSoal lain tidak berubah. Soal yang dipakai Paket Ujian, jawaban siswa, atau analisis tidak dapat dihapus.');
        }
        document.addEventListener('DOMContentLoaded', function () {
            const all = document.getElementById('pilihSemuaSoal');
            const checks = Array.from(document.querySelectorAll('.pilih-soal'));
            const count = document.getElementById('jumlahSoalDipilih');
            const button = document.getElementById('tombolHapusTerpilih');
            function refresh() {
                const n = checks.filter(el => el.checked).length;
                count.textContent = n + ' soal dipilih';
                button.disabled = n === 0;
                all.checked = checks.length > 0 && n === checks.length;
                all.indeterminate = n > 0 && n < checks.length;
            }
            all.addEventListener('change', function () {
                checks.forEach(el => { el.checked = all.checked; });
                refresh();
            });
            checks.forEach(el => el.addEventListener('change', refresh));
            refresh();
        });

        function validateAndSubmit(input) {
            if (!input.files || input.files.length === 0) return;

            const file = input.files[0];
            const fileName = file.name.toLowerCase();
            const isValid = fileName.endsWith('.xlsx') || fileName.endsWith('.xls') || fileName.endsWith('.csv');

            if (!isValid) {
                alert('❌ Format file tidak sesuai!\n\nHarap unggah file Excel (.xlsx, .xls) atau CSV (.csv). Gambar tertanam hanya didukung dalam .xlsx.');
                input.value = '';
                return;
            }

            document.getElementById('formImportSoal').submit();
        }
    </script>

{{-- [EDUXID-BANK-PREVIEW-V1] --}}
@if(Route::has('guru.bankSoal.preview'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    const base = @json(url('/ujian/bank-soal'));
    Array.from(document.querySelectorAll('a[href*="/bank-soal/"][href*="/edit"]')).forEach(function (editLink) {
        if (editLink.parentElement?.querySelector('[data-eduxid-preview]')) return;
        const match = editLink.getAttribute('href')?.match(/\/bank-soal\/(\d+)\/edit(?:$|\?)/);
        if (!match) return;
        const preview = document.createElement('a');
        preview.href = base + '/' + match[1] + '/preview'; preview.target='_blank'; preview.rel='noopener';
        preview.setAttribute('data-eduxid-preview','1');
        preview.className='inline-flex items-center gap-1 px-2.5 py-1.5 rounded-md bg-sky-50 text-sky-700 hover:bg-sky-100 text-xs font-semibold transition';
        preview.innerHTML='<span aria-hidden="true">👁</span><span>Preview</span>';
        editLink.parentNode.insertBefore(preview, editLink);
    });
});
</script>
@endif
</x-app-layout>
