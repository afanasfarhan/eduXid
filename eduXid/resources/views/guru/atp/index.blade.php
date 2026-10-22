<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">ATP</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Alur Tujuan Pembelajaran per mata pelajaran dan tingkat kelas.
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(session('success'))
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-emerald-800">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-red-800">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-red-800">
                    <ul class="list-disc list-inside text-sm">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(($legacyCount ?? 0) > 0)
                <div class="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-amber-800 text-sm">
                    Ada {{ $legacyCount }} ATP lama yang belum memiliki tingkat kelas.
                    Edit ATP lama tersebut untuk menetapkan tingkat kelasnya.
                </div>
            @endif

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                <form method="GET" action="{{ route('guru.atp.index') }}"
                      class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-3xl">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Mata Pelajaran</label>
                        <select name="subject"
                                onchange="this.form.querySelector('[name=grade]').value=''; this.form.submit()"
                                class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                            @forelse($subjects as $subject)
                                <option value="{{ $subject->id }}" @selected((int)$subjectId === (int)$subject->id)>
                                    {{ $subject->nama_mata_pelajaran }}
                                </option>
                            @empty
                                <option value="">Belum ada penugasan mengajar</option>
                            @endforelse
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Tingkat Kelas</label>
                        <select name="grade"
                                onchange="this.form.submit()"
                                class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500">
                            @forelse($grades as $grade)
                                <option value="{{ $grade }}" @selected((int)$gradeLevel === (int)$grade)>
                                    Kelas {{ $grade }}
                                </option>
                            @empty
                                <option value="">Belum ada tingkat kelas pada penugasan</option>
                            @endforelse
                        </select>
                    </div>
                </form>
            </div>

            @if($subjectId > 0 && $gradeLevel > 0)
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <div>
                            <h3 class="font-bold text-gray-800">Upload / Download ATP</h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Gunakan Excel untuk input massal ATP {{ $selectedSubject->nama_mata_pelajaran ?? '' }}
                                Kelas {{ $gradeLevel }}. File hasil Download ATP dapat diedit lalu di-upload kembali.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('guru.atp.download', ['subject' => $subjectId, 'grade' => $gradeLevel]) }}"
                               class="inline-flex items-center justify-center px-4 py-2.5 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700">
                                ⬇️ Download ATP
                            </a>

                            <button type="button"
                                    onclick="document.getElementById('atp-upload-panel').classList.toggle('hidden')"
                                    class="inline-flex items-center justify-center px-4 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700">
                                ⬆️ Upload ATP
                            </button>
                        </div>
                    </div>

                    <div id="atp-upload-panel" class="hidden mt-5 pt-5 border-t">
                        <form method="POST"
                              action="{{ route('guru.atp.upload') }}"
                              enctype="multipart/form-data"
                              class="flex flex-col lg:flex-row lg:items-end gap-4">
                            @csrf
                            <input type="hidden" name="subject_id" value="{{ $subjectId }}">
                            <input type="hidden" name="grade_level" value="{{ $gradeLevel }}">

                            <div class="flex-1">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    File Excel ATP (.xlsx / .xls)
                                </label>
                                <input type="file"
                                       name="atp_file"
                                       accept=".xlsx,.xls"
                                       required
                                       class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                            </div>

                            <button class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                                Impor ATP
                            </button>
                        </form>

                        <div class="mt-4 rounded-lg bg-gray-50 border border-gray-200 p-4 text-xs text-gray-600">
                            <strong>Format kolom:</strong>
                            Tingkat Kelas | Urutan | Materi | Tujuan Pembelajaran | Indikator Penilaian.
                            Tingkat Kelas pada file harus sama dengan Kelas {{ $gradeLevel }} yang sedang dipilih.
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                        <h3 class="font-bold text-gray-800">Tambah ATP</h3>
                        <p class="text-sm text-gray-500 mt-1 mb-5">
                            {{ $selectedSubject->nama_mata_pelajaran ?? 'Mata Pelajaran' }}
                            · Kelas {{ $gradeLevel }}
                        </p>

                        <form id="atp-create-form" method="POST" action="{{ route('guru.atp.store') }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="subject_id" value="{{ $subjectId }}">
                            <input type="hidden" name="grade_level" value="{{ $gradeLevel }}">

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Urutan</label>
                                <input type="number" name="sort_order" min="0"
                                       value="{{ old('sort_order', $items->count() + 1) }}"
                                       class="w-full rounded-lg border-gray-300">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Materi / Lingkup Materi
                                </label>
                                <input type="text" name="materi" required value="{{ old('materi') }}"
                                       placeholder="Contoh: Vektor dan Operasi Vektor"
                                       class="w-full rounded-lg border-gray-300">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Tujuan Pembelajaran
                                </label>
                                <textarea name="tujuan_pembelajaran" rows="4" required
                                          placeholder="Contoh: Mampu merepresentasikan besaran vektor, melakukan operasi penjumlahan, pengurangan, dan penguraian vektor, serta menerapkannya untuk memecahkan masalah fisika."
                                          class="w-full rounded-lg border-gray-300">{{ old('tujuan_pembelajaran') }}</textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Indikator Penilaian
                                </label>
                                <textarea name="indikator_penilaian" rows="4" required
                                          placeholder="Contoh: Menentukan besar dan arah vektor; menguraikan vektor ke komponen sumbu x dan y; menentukan resultan dua atau lebih vektor secara grafis dan analitis; menerapkan operasi vektor pada konteks perpindahan, kecepatan, atau gaya."
                                          class="w-full rounded-lg border-gray-300">{{ old('indikator_penilaian') }}</textarea>
                            </div>

                        </form>

                        <div class="grid grid-cols-2 items-stretch gap-2 mt-4">
                            <button type="submit" form="atp-create-form"
                                    class="inline-flex w-full h-full min-h-11 items-center justify-center rounded-lg bg-indigo-600 px-3 py-2.5 text-center text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                Simpan ATP
                            </button>

                            <form method="POST" action="{{ route('guru.atp.destroyAll') }}"
                                  class="w-full"
                                  onsubmit="return confirm('Hapus SEMUA ATP yang ditampilkan untuk mata pelajaran dan kelas ini? Tindakan ini tidak dapat dibatalkan.');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="subject_id" value="{{ $subjectId }}">
                                <input type="hidden" name="grade_level" value="{{ $gradeLevel }}">
                                <input type="hidden" name="confirm_delete_all" value="yes">
                                <button type="submit" @disabled($items->isEmpty())
                                        class="inline-flex w-full h-full min-h-11 items-center justify-center rounded-lg bg-red-600 px-3 py-2.5 text-center text-sm font-semibold text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                                    Hapus Semua ATP
                                </button>
                            </form>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">
                            Hapus Semua ATP hanya berlaku untuk {{ $selectedSubject->nama_mata_pelajaran ?? 'mata pelajaran terpilih' }}
                            Kelas {{ $gradeLevel }} yang sedang ditampilkan.
                        </p>
                    </div>

                    <div class="xl:col-span-2 space-y-4">
                        @forelse($items as $item)
                            <div x-data="{ editing: false }"
                                 class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">

                                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex items-center justify-center min-w-9 h-9 px-2 rounded-full bg-indigo-50 text-indigo-700 font-bold">
                                                {{ $item->sort_order ?: $loop->iteration }}
                                            </span>
                                            <div>
                                                <div class="text-xs font-bold uppercase tracking-wide text-indigo-600">
                                                    Kelas {{ $item->grade_level }}
                                                </div>
                                                <h3 class="font-bold text-gray-900">{{ $item->materi }}</h3>
                                            </div>
                                        </div>

                                        <div class="mt-4 grid grid-cols-1 gap-3 text-sm">
                                            <div class="rounded-lg bg-gray-50 border p-3">
                                                <div class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">
                                                    Tujuan Pembelajaran
                                                </div>
                                                <div class="text-gray-800 whitespace-pre-line">{{ $item->tujuan_pembelajaran }}</div>
                                            </div>

                                            <div class="rounded-lg bg-gray-50 border p-3">
                                                <div class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">
                                                    Indikator Penilaian
                                                </div>
                                                <div class="text-gray-800 whitespace-pre-line">{{ $item->indikator_penilaian }}</div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 gap-2">
                                        <button type="button"
                                                @click="editing = !editing"
                                                class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-amber-50 text-amber-700 border border-amber-200 text-sm font-semibold hover:bg-amber-100">
                                            ✏️ Edit
                                        </button>

                                        <form method="POST"
                                              action="{{ route('guru.atp.destroy', $item->id) }}"
                                              onsubmit="return confirm('Hapus ATP ini? ATP yang sudah dipakai pada riwayat nilai tidak menghapus nilai murid.')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-red-50 text-red-700 border border-red-200 text-sm font-semibold hover:bg-red-100">
                                                🗑️ Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <form x-show="editing" x-cloak
                                      method="POST"
                                      action="{{ route('guru.atp.update', $item->id) }}"
                                      class="mt-5 pt-5 border-t space-y-4">
                                    @csrf
                                    @method('PUT')

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-500 mb-1">Tingkat Kelas</label>
                                            <select name="grade_level" class="w-full rounded-lg border-gray-300 text-sm" required>
                                                @foreach($grades as $grade)
                                                    <option value="{{ $grade }}" @selected((int)$item->grade_level === (int)$grade)>
                                                        Kelas {{ $grade }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-500 mb-1">Urutan</label>
                                            <input type="number" name="sort_order" min="0" value="{{ $item->sort_order }}"
                                                   class="w-full rounded-lg border-gray-300 text-sm">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-500 mb-1">Materi</label>
                                            <input type="text" name="materi" required value="{{ $item->materi }}"
                                                   class="w-full rounded-lg border-gray-300 text-sm">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 mb-1">Tujuan Pembelajaran</label>
                                        <textarea name="tujuan_pembelajaran" rows="3" required
                                                  class="w-full rounded-lg border-gray-300 text-sm">{{ $item->tujuan_pembelajaran }}</textarea>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 mb-1">Indikator Penilaian</label>
                                        <textarea name="indikator_penilaian" rows="3" required
                                                  class="w-full rounded-lg border-gray-300 text-sm">{{ $item->indikator_penilaian }}</textarea>
                                    </div>

                                    <div class="flex justify-end gap-2">
                                        <button type="button" @click="editing = false"
                                                class="px-4 py-2 rounded-lg border text-gray-700 text-sm font-semibold">
                                            Batal
                                        </button>
                                        <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-700">
                                            Simpan Perubahan
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @empty
                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm px-6 py-12 text-center text-gray-500">
                                Belum ada ATP untuk {{ $selectedSubject->nama_mata_pelajaran ?? 'mata pelajaran' }}
                                Kelas {{ $gradeLevel }}.
                            </div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-800">
                    ATP Kelas {{ $gradeLevel }} menjadi sumber bersama untuk
                    <strong>LMS, Bank Soal, Paket Ujian, Buku Nilai, PWA Guru, dan Deskripsi Capaian Kompetensi Rapor.</strong>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
