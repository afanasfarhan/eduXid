<x-app-layout>

{{-- =========================================================
     HEADER
     ========================================================= --}}
<x-slot name="header">

    <div class="flex items-center justify-between gap-4 flex-wrap">

        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-indigo-100 text-indigo-600">
                    ✏️
                </span>

                <div>
                    <h2 class="font-bold text-xl text-gray-800 leading-tight">
                        Edit Aktivitas
                    </h2>

                    <p class="text-sm text-gray-500">
                        Ubah informasi tugas atau aktivitas CBT.
                    </p>
                </div>
            </div>
        </div>

        <a href="{{ route('lms.courses.show', $assignment->topic->course) }}"
           class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700
                  hover:bg-gray-50 text-sm font-bold px-4 py-2.5 rounded-lg shadow-sm transition">
            ← Kembali ke Kelas
        </a>

    </div>

</x-slot>


{{-- =========================================================
     CONTENT
     ========================================================= --}}
<div class="py-8 md:py-10">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">


        {{-- =====================================================
             ERROR SESSION
             ===================================================== --}}
        @if (session('error'))

            <div class="mb-6 flex items-start gap-3 rounded-xl border border-red-200
                        bg-red-50 px-5 py-4 text-red-700 shadow-sm">

                <div class="text-xl">
                    ⚠️
                </div>

                <div>
                    <p class="font-bold">
                        Terjadi kesalahan
                    </p>

                    <p class="text-sm mt-0.5">
                        {{ session('error') }}
                    </p>
                </div>

            </div>

        @endif


        {{-- =====================================================
             VALIDATION ERROR
             ===================================================== --}}
        @if ($errors->any())

            <div class="mb-6 rounded-xl border border-red-200 bg-red-50
                        shadow-sm overflow-hidden">

                <div class="px-5 py-4 border-b border-red-200 flex items-center gap-3">

                    <span class="text-xl">
                        ⚠️
                    </span>

                    <div>
                        <p class="font-bold text-red-800">
                            Periksa data berikut
                        </p>

                        <p class="text-xs text-red-600 mt-0.5">
                            Beberapa bagian belum diisi dengan benar.
                        </p>
                    </div>

                </div>

                <div class="px-5 py-4">

                    <ul class="list-disc list-inside text-sm text-red-700 space-y-1">

                        @foreach ($errors->all() as $error)

                            <li>
                                {{ $error }}
                            </li>

                        @endforeach

                    </ul>

                </div>

            </div>

        @endif


        {{-- =====================================================
             MAIN GRID
             ===================================================== --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">


            {{-- =================================================
                 FORM
                 ================================================= --}}
            <div class="lg:col-span-2">

                <form method="POST"
                      action="{{ route('lms.assignments.update', $assignment) }}"
                      class="space-y-6">

                    @csrf

        @include('guru.atp.selector-lms', ['topic' => $assignment->topic, 'selectedAtpId' => old('atp_item_id', $assignment->atp_item_id ?? null)])
                    @method('PUT')


                    {{-- =================================================
                         INFORMASI AKTIVITAS
                         ================================================= --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

                        <div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">

                            <div class="flex items-center gap-3">

                                <div class="w-10 h-10 rounded-xl bg-indigo-100
                                            flex items-center justify-center text-xl">
                                    📚
                                </div>

                                <div>

                                    <h3 class="font-bold text-indigo-900">
                                        Informasi Aktivitas
                                    </h3>

                                    <p class="text-xs text-indigo-600 mt-0.5">
                                        Informasi utama aktivitas pembelajaran.
                                    </p>

                                </div>

                            </div>

                        </div>


                        <div class="p-6 space-y-6">


                            {{-- TOPIK --}}
                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-2">
                                    Topik Pembelajaran
                                </label>

                                <div class="flex items-center gap-3 rounded-xl
                                            border border-gray-200 bg-gray-50 px-4 py-3">

                                    <div class="w-9 h-9 rounded-lg bg-indigo-100
                                                text-indigo-600 flex items-center justify-center">
                                        📖
                                    </div>

                                    <div class="min-w-0">

                                        <p class="text-sm font-bold text-gray-800 truncate">
                                            {{ $assignment->topic->title }}
                                        </p>

                                        <p class="text-xs text-gray-400 mt-0.5">
                                            Topik tidak dapat diubah dari halaman ini.
                                        </p>

                                    </div>

                                </div>

                            </div>


                            {{-- JENIS AKTIVITAS --}}
                            <div>

                                <label for="assignment_type"
                                       class="block text-sm font-bold text-gray-700 mb-2">

                                    Jenis Aktivitas

                                    <span class="text-red-500">*</span>

                                </label>

                                <select name="assignment_type"
                                        id="assignment_type"
                                        required
                                        class="block w-full border border-gray-300 rounded-xl
                                               shadow-sm text-sm p-3
                                               focus:border-indigo-500 focus:ring-2
                                               focus:ring-indigo-200 focus:outline-none">

                                    <option value="assignment"
                                        @selected(old('assignment_type', $assignment->assignment_type ?? 'assignment') === 'assignment')>
                                        📝 Tugas
                                    </option>

                                    <option value="cbt"
                                        @selected(old('assignment_type', $assignment->assignment_type ?? 'assignment') === 'cbt')>
                                        🖥️ CBT / Ujian
                                    </option>

                                </select>

                                @error('assignment_type')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror

                            </div>


                            {{-- JUDUL --}}
                            <div>

                                <label for="title"
                                       class="block text-sm font-bold text-gray-700 mb-2">

                                    Judul Aktivitas

                                    <span class="text-red-500">*</span>

                                </label>

                                <input type="text"
                                       name="title"
                                       id="title"
                                       value="{{ old('title', $assignment->title) }}"
                                       placeholder="Contoh: Tugas Pengamatan Gerak"
                                       required
                                       autofocus
                                       class="block w-full border rounded-xl shadow-sm text-sm p-3
                                              focus:border-indigo-500 focus:ring-2
                                              focus:ring-indigo-200 focus:outline-none
                                              {{ $errors->has('title')
                                                  ? 'border-red-500 bg-red-50'
                                                  : 'border-gray-300' }}">

                                @error('title')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror

                            </div>


                            {{-- INSTRUKSI --}}
                            <div>

                                <div class="flex items-center justify-between mb-2">

                                    <label for="instructions"
                                           class="block text-sm font-bold text-gray-700">
                                        Instruksi
                                    </label>

                                    <span class="text-xs text-gray-400">
                                        Opsional
                                    </span>

                                </div>

                                <textarea name="instructions"
                                          id="instructions"
                                          rows="7"
                                          placeholder="Tuliskan petunjuk atau instruksi pengerjaan..."
                                          class="block w-full border rounded-xl shadow-sm text-sm p-3
                                                 focus:border-indigo-500 focus:ring-2
                                                 focus:ring-indigo-200 focus:outline-none
                                                 resize-y
                                                 {{ $errors->has('instructions')
                                                     ? 'border-red-500 bg-red-50'
                                                     : 'border-gray-300' }}">{{ old('instructions', $assignment->instructions) }}</textarea>

                                @error('instructions')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror

                            </div>

                        </div>

                    </div>



                    {{-- =================================================
                         PENGATURAN AKTIVITAS
                         ================================================= --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

                        <div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">

                            <div class="flex items-center gap-3">

                                <div class="w-10 h-10 rounded-xl bg-indigo-100
                                            flex items-center justify-center text-xl">
                                    ⚙️
                                </div>

                                <div>

                                    <h3 class="font-bold text-indigo-900">
                                        Pengaturan Aktivitas
                                    </h3>

                                    <p class="text-xs text-indigo-600 mt-0.5">
                                        Atur nilai, pengumpulan, dan batas waktu.
                                    </p>

                                </div>

                            </div>

                        </div>


                        <div class="p-6">


                            {{-- =================================================
                                 TUGAS BIASA
                                 ================================================= --}}
                            <div id="assignment-settings"
                                 class="space-y-6">


                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">


                                    {{-- NILAI --}}
                                    <div>

                                        <label for="max_score"
                                               class="block text-sm font-bold text-gray-700 mb-2">

                                            Nilai Maksimal

                                            <span class="text-red-500">*</span>

                                        </label>

                                        <div class="relative">

                                            <input type="number"
                                                   name="max_score"
                                                   id="max_score"
                                                   value="{{ old('max_score', $assignment->max_score ?? 100) }}"
                                                   min="0"
                                                   max="100"
                                                   step="0.01"
                                                   class="block w-full border rounded-xl shadow-sm
                                                          text-sm p-3 pr-14
                                                          focus:border-indigo-500
                                                          focus:ring-2 focus:ring-indigo-200
                                                          focus:outline-none
                                                          {{ $errors->has('max_score')
                                                              ? 'border-red-500 bg-red-50'
                                                              : 'border-gray-300' }}">

                                            <span class="absolute right-3 top-1/2 -translate-y-1/2
                                                         text-xs font-bold text-gray-400">
                                                / 100
                                            </span>

                                        </div>

                                        @error('max_score')

                                            <p class="text-xs text-red-600 mt-1">
                                                {{ $message }}
                                            </p>

                                        @enderror

                                    </div>


                                    {{-- PENGUMPULAN --}}
                                    <div>

                                        <label for="submission_type"
                                               class="block text-sm font-bold text-gray-700 mb-2">

                                            Bentuk Pengumpulan

                                            <span class="text-red-500">*</span>

                                        </label>

                                        <select name="submission_type"
                                                id="submission_type"
                                                class="block w-full border border-gray-300
                                                       rounded-xl shadow-sm text-sm p-3
                                                       focus:border-indigo-500
                                                       focus:ring-2 focus:ring-indigo-200
                                                       focus:outline-none">

                                            <option value="text"
                                                @selected(old('submission_type', $assignment->submission_type) === 'text')>
                                                📝 Teks
                                            </option>

                                            <option value="file"
                                                @selected(old('submission_type', $assignment->submission_type) === 'file')>
                                                📎 File
                                            </option>

                                            <option value="text_file"
                                                @selected(old('submission_type', $assignment->submission_type) === 'text_file')>
                                                📝📎 Teks / File
                                            </option>

                                        </select>

                                        @error('submission_type')

                                            <p class="text-xs text-red-600 mt-1">
                                                {{ $message }}
                                            </p>

                                        @enderror

                                    </div>

                                </div>


                                {{-- BATAS PENGUMPULAN --}}
                                <div>

                                    <label for="due_at"
                                           class="block text-sm font-bold text-gray-700 mb-2">

                                        Batas Pengumpulan

                                    </label>

                                    <input type="datetime-local"
                                           name="due_at"
                                           id="due_at"
                                           value="{{ old('due_at', $assignment->due_at ? $assignment->due_at->format('Y-m-d\TH:i') : '') }}"
                                           class="block w-full border border-gray-300
                                                  rounded-xl shadow-sm text-sm p-3
                                                  focus:border-indigo-500
                                                  focus:ring-2 focus:ring-indigo-200
                                                  focus:outline-none">

                                    @error('due_at')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                    <div class="flex items-center gap-2 mt-2 text-xs text-gray-400">
                                        <span>ℹ️</span>
                                        <span>
                                            Kosongkan jika tugas tidak memiliki batas waktu.
                                        </span>
                                    </div>

                                </div>

                            </div>



                            {{-- =================================================
                                 CBT
                                 ================================================= --}}
                            <div id="cbt-settings"
                                 class="hidden space-y-6">


                                {{-- PILIH UJIAN --}}
                                <div>

                                    <label for="exam_id"
                                           class="block text-sm font-bold text-gray-700 mb-2">

                                        Ujian CBT

                                        <span class="text-red-500">*</span>

                                    </label>

                                    <select name="exam_id"
                                            id="exam_id"
                                            class="block w-full border border-gray-300
                                                   rounded-xl shadow-sm text-sm p-3
                                                   focus:border-indigo-500
                                                   focus:ring-2 focus:ring-indigo-200
                                                   focus:outline-none">

                                        <option value="">
                                            -- Pilih Ujian CBT --
                                        </option>

                                        @foreach ($exams as $exam)

                                            <option value="{{ $exam->id }}"
                                                    data-duration="{{ $exam->durasi_menit }}"
                                                    data-start="{{ $exam->waktu_mulai }}"
                                                    data-end="{{ $exam->waktu_selesai }}"
                                                    @selected(old('exam_id', $assignment->exam_id) == $exam->id)>

                                                {{ $exam->judul_ujian }}

                                            </option>

                                        @endforeach

                                    </select>

                                    @error('exam_id')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>


                                {{-- INFORMASI UJIAN --}}
                                <div id="exam-info"
                                     class="hidden rounded-xl border border-indigo-100
                                            bg-indigo-50 overflow-hidden">

                                    <div class="px-4 py-3 border-b border-indigo-100">

                                        <p class="text-xs font-bold text-indigo-700 uppercase tracking-wide">
                                            Informasi Ujian
                                        </p>

                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-0">

                                        <div class="px-4 py-4 border-b sm:border-b-0 sm:border-r border-indigo-100">

                                            <p class="text-xs text-gray-500">
                                                Durasi
                                            </p>

                                            <p id="exam-duration"
                                               class="font-bold text-indigo-900 mt-1">
                                                -
                                            </p>

                                        </div>

                                        <div class="px-4 py-4 border-b sm:border-b-0 sm:border-r border-indigo-100">

                                            <p class="text-xs text-gray-500">
                                                Waktu Mulai
                                            </p>

                                            <p id="exam-start"
                                               class="font-bold text-indigo-900 mt-1">
                                                -
                                            </p>

                                        </div>

                                        <div class="px-4 py-4">

                                            <p class="text-xs text-gray-500">
                                                Waktu Selesai
                                            </p>

                                            <p id="exam-end"
                                               class="font-bold text-indigo-900 mt-1">
                                                -
                                            </p>

                                        </div>

                                    </div>

                                </div>


                                {{-- NILAI CBT --}}
                                <div>

                                    <label for="cbt_max_score"
                                           class="block text-sm font-bold text-gray-700 mb-2">

                                        Nilai Maksimal

                                        <span class="text-red-500">*</span>

                                    </label>

                                    <div class="relative">

                                        <input type="number"
                                               name="cbt_max_score"
                                               id="cbt_max_score"
                                               value="{{ old('cbt_max_score', $assignment->max_score ?? 100) }}"
                                               min="0"
                                               max="100"
                                               step="0.01"
                                               class="block w-full border border-gray-300
                                                      rounded-xl shadow-sm text-sm p-3 pr-14
                                                      focus:border-indigo-500
                                                      focus:ring-2 focus:ring-indigo-200
                                                      focus:outline-none">

                                        <span class="absolute right-3 top-1/2 -translate-y-1/2
                                                     text-xs font-bold text-gray-400">
                                            / 100
                                        </span>

                                    </div>

                                    <p class="text-xs text-gray-400 mt-2">
                                        Nilai maksimal yang digunakan untuk aktivitas CBT.
                                    </p>

                                </div>

                            </div>



                            {{-- =================================================
                                 STATUS PUBLIKASI
                                 ================================================= --}}
                            <div class="mt-6 rounded-xl border border-gray-200
                                        bg-gray-50 p-4">

                                <div class="flex items-center justify-between gap-4">

                                    <div class="flex items-start gap-3">

                                        <div class="w-10 h-10 rounded-lg bg-white
                                                    border border-gray-200
                                                    flex items-center justify-center">
                                            📢
                                        </div>

                                        <div>

                                            <label for="is_published"
                                                   class="text-sm font-bold text-gray-800 cursor-pointer">
                                                Publikasikan aktivitas
                                            </label>

                                            <p class="text-xs text-gray-500 mt-1">
                                                Murid dapat melihat dan mengerjakan aktivitas
                                                jika status ini diaktifkan.
                                            </p>

                                        </div>

                                    </div>


                                    <label class="relative inline-flex items-center cursor-pointer shrink-0">

                                        <input type="hidden"
                                               name="is_published"
                                               value="0">

                                        <input type="checkbox"
                                               id="is_published"
                                               name="is_published"
                                               value="1"
                                               @checked(old('is_published', $assignment->is_published))
                                               class="sr-only peer">

                                        <div class="w-11 h-6 bg-gray-300 rounded-full
                                                    peer peer-checked:bg-indigo-600
                                                    peer-focus:ring-4 peer-focus:ring-indigo-200
                                                    transition-all">

                                        </div>

                                        <div class="absolute left-0.5 top-0.5
                                                    w-5 h-5 bg-white rounded-full
                                                    shadow-sm transition-transform
                                                    peer-checked:translate-x-5">

                                        </div>

                                    </label>

                                </div>

                            </div>

                        </div>

                    </div>



                    {{-- =================================================
                         ACTION BAR
                         ================================================= --}}
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-200
                                px-5 py-4">

                        <div class="flex flex-col sm:flex-row
                                    sm:items-center sm:justify-between gap-3">

                            <a href="{{ route('lms.assignments.show', $assignment) }}"
                               class="inline-flex items-center justify-center gap-2
                                      bg-gray-100 text-gray-700 border border-gray-200
                                      px-5 py-2.5 rounded-xl hover:bg-gray-200
                                      transition text-sm font-bold">

                                ← Batal

                            </a>

                            <button type="submit"
                                    class="inline-flex items-center justify-center gap-2
                                           bg-indigo-600 text-white px-6 py-2.5
                                           rounded-xl hover:bg-indigo-700
                                           transition text-sm font-bold shadow-sm">

                                💾 Simpan Perubahan

                            </button>

                        </div>

                    </div>


                </form>

            </div>



            {{-- =================================================
                 SIDEBAR
                 ================================================= --}}
            <div class="space-y-6">


                {{-- =================================================
                     TOPIK PEMBELAJARAN
                     ================================================= --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200
                            overflow-hidden">

                    <div class="px-5 py-4 border-b border-gray-100
                                flex items-center gap-3">

                        <div class="w-9 h-9 rounded-lg bg-indigo-100
                                    text-indigo-600 flex items-center justify-center">
                            📚
                        </div>

                        <div>

                            <h4 class="font-bold text-gray-800">
                                Topik Pembelajaran
                            </h4>

                            <p class="text-xs text-gray-400">
                                Aktivitas ini berada pada topik
                            </p>

                        </div>

                    </div>


                    <div class="p-5">

                        <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-4">

                            <p class="font-bold text-indigo-900">
                                {{ $assignment->topic->title }}
                            </p>

                            @if($assignment->topic->description)

                                <p class="text-sm text-gray-500 mt-2 leading-relaxed">
                                    {{ $assignment->topic->description }}
                                </p>

                            @endif

                        </div>

                    </div>

                </div>



                {{-- =================================================
                     CBT HELP
                     ================================================= --}}
                <div id="cbt-help"
                     class="hidden bg-white rounded-2xl shadow-sm border border-gray-200
                            overflow-hidden">

                    <div class="px-5 py-4 border-b border-gray-100
                                flex items-center gap-3">

                        <div class="w-9 h-9 rounded-lg bg-indigo-100
                                    text-indigo-600 flex items-center justify-center">
                            🖥️
                        </div>

                        <div>

                            <h4 class="font-bold text-gray-800">
                                Aktivitas CBT
                            </h4>

                            <p class="text-xs text-gray-400">
                                Hubungan dengan modul CBT
                            </p>

                        </div>

                    </div>


                    <div class="p-5">

                        <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-4">

                            <p class="text-sm text-gray-600 leading-relaxed">
                                Aktivitas CBT terhubung langsung dengan ujian
                                yang sudah dibuat pada modul CBT.
                            </p>

                            <p class="text-sm text-gray-600 mt-3 leading-relaxed">
                                Murid nantinya mengerjakan ujian melalui
                                aktivitas ini.
                            </p>

                        </div>

                    </div>

                </div>



                {{-- =================================================
                     ASSIGNMENT HELP
                     ================================================= --}}
                <div id="assignment-help"
                     class="bg-white rounded-2xl shadow-sm border border-gray-200
                            overflow-hidden">

                    <div class="px-5 py-4 border-b border-gray-100
                                flex items-center gap-3">

                        <div class="w-9 h-9 rounded-lg bg-indigo-100
                                    text-indigo-600 flex items-center justify-center">
                            💡
                        </div>

                        <div>

                            <h4 class="font-bold text-gray-800">
                                Tips Mengedit Tugas
                            </h4>

                            <p class="text-xs text-gray-400">
                                Hal yang perlu diperiksa
                            </p>

                        </div>

                    </div>


                    <div class="p-5">

                        <div class="space-y-4">


                            <div class="flex items-start gap-3">

                                <span class="w-6 h-6 rounded-full bg-indigo-50
                                             text-indigo-600 flex items-center
                                             justify-center text-xs shrink-0">
                                    1
                                </span>

                                <p class="text-sm text-gray-500 leading-relaxed">
                                    Periksa kembali judul dan instruksi aktivitas.
                                </p>

                            </div>


                            <div class="flex items-start gap-3">

                                <span class="w-6 h-6 rounded-full bg-indigo-50
                                             text-indigo-600 flex items-center
                                             justify-center text-xs shrink-0">
                                    2
                                </span>

                                <p class="text-sm text-gray-500 leading-relaxed">
                                    Pastikan nilai maksimal sesuai sistem penilaian.
                                </p>

                            </div>


                            <div class="flex items-start gap-3">

                                <span class="w-6 h-6 rounded-full bg-indigo-50
                                             text-indigo-600 flex items-center
                                             justify-center text-xs shrink-0">
                                    3
                                </span>

                                <p class="text-sm text-gray-500 leading-relaxed">
                                    Periksa kembali batas waktu pengumpulan.
                                </p>

                            </div>


                            <div class="flex items-start gap-3">

                                <span class="w-6 h-6 rounded-full bg-indigo-50
                                             text-indigo-600 flex items-center
                                             justify-center text-xs shrink-0">
                                    4
                                </span>

                                <p class="text-sm text-gray-500 leading-relaxed">
                                    Pastikan status publikasi sesuai kebutuhan.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>


            </div>

        </div>

    </div>

</div>


{{-- =============================================================
     JAVASCRIPT
     ============================================================= --}}
<script>

    document.addEventListener('DOMContentLoaded', function () {

        const typeSelect =
            document.getElementById('assignment_type');

        const assignmentSettings =
            document.getElementById('assignment-settings');

        const cbtSettings =
            document.getElementById('cbt-settings');

        const assignmentHelp =
            document.getElementById('assignment-help');

        const cbtHelp =
            document.getElementById('cbt-help');

        const submissionType =
            document.getElementById('submission_type');

        const maxScore =
            document.getElementById('max_score');

        const cbtMaxScore =
            document.getElementById('cbt_max_score');

        const examSelect =
            document.getElementById('exam_id');

        const examInfo =
            document.getElementById('exam-info');

        const examDuration =
            document.getElementById('exam-duration');

        const examStart =
            document.getElementById('exam-start');

        const examEnd =
            document.getElementById('exam-end');


        function updateActivityType() {

            const type = typeSelect.value;


            if (type === 'cbt') {

                assignmentSettings.classList.add('hidden');

                cbtSettings.classList.remove('hidden');

                assignmentHelp.classList.add('hidden');

                cbtHelp.classList.remove('hidden');

                submissionType.removeAttribute('required');

                maxScore.removeAttribute('required');

                examSelect.setAttribute('required', 'required');

                cbtMaxScore.setAttribute('required', 'required');

            } else {

                assignmentSettings.classList.remove('hidden');

                cbtSettings.classList.add('hidden');

                assignmentHelp.classList.remove('hidden');

                cbtHelp.classList.add('hidden');

                submissionType.setAttribute('required', 'required');

                maxScore.setAttribute('required', 'required');

                examSelect.removeAttribute('required');

                cbtMaxScore.removeAttribute('required');

            }

        }


        function updateExamInfo() {

            const option =
                examSelect.options[examSelect.selectedIndex];


            if (!examSelect.value) {

                examInfo.classList.add('hidden');

                examDuration.textContent = '-';

                examStart.textContent = '-';

                examEnd.textContent = '-';

                return;

            }


            const duration =
                option.dataset.duration;

            const start =
                option.dataset.start;

            const end =
                option.dataset.end;


            examDuration.textContent =
                duration
                    ? duration + ' menit'
                    : '-';

            examStart.textContent =
                start
                    ? start
                    : 'Tidak ditentukan';

            examEnd.textContent =
                end
                    ? end
                    : 'Tidak ditentukan';


            examInfo.classList.remove('hidden');

        }


        typeSelect.addEventListener(
            'change',
            updateActivityType
        );


        examSelect.addEventListener(
            'change',
            updateExamInfo
        );


        updateActivityType();

        updateExamInfo();

    });

</script>

</x-app-layout>
