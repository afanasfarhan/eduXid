<x-app-layout>

<x-slot name="header">

    <div class="flex items-center justify-between flex-wrap gap-3">

        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Tambah Aktivitas
            </h2>

            <p class="text-sm text-gray-500 mt-0.5">
                Buat tugas atau aktivitas CBT untuk topik ini.
            </p>
        </div>

        <a href="{{ route('lms.courses.show', $topic->course) }}"
           class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">

            ← Kembali ke Kelas

        </a>

    </div>

</x-slot>


<div class="py-12">

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">


        {{-- =====================================================
             PESAN ERROR
             ===================================================== --}}

        @if (session('error'))

            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">

                {{ session('error') }}

            </div>

        @endif


        {{-- =====================================================
             VALIDATION ERROR
             ===================================================== --}}

        @if ($errors->any())

            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded-r-lg mb-6 shadow-sm flex items-start gap-3">

                <span class="text-xl shrink-0">
                    ⚠️
                </span>

                <div>

                    <p class="font-bold">
                        Periksa data berikut:
                    </p>

                    <ul class="list-disc list-inside text-sm mt-1 space-y-0.5">

                        @foreach ($errors->all() as $error)

                            <li>
                                {{ $error }}
                            </li>

                        @endforeach

                    </ul>

                </div>

            </div>

        @endif


        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">


            {{-- =================================================
                 FORM UTAMA
                 ================================================= --}}

            <div class="lg:col-span-2">

                <form method="POST"
                      action="{{ route('lms.assignments.store', $topic) }}"
                      class="space-y-6">

                    @csrf

        @include('guru.atp.selector-lms', ['topic' => $topic, 'selectedAtpId' => old('atp_item_id')])


                    {{-- =================================================
                         INFORMASI AKTIVITAS
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">

                        <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100">

                            <h3 class="font-bold text-indigo-900">
                                📚 Informasi Aktivitas
                            </h3>

                            <p class="text-xs text-indigo-500 mt-0.5">
                                Tentukan informasi utama aktivitas pembelajaran.
                            </p>

                        </div>


                        <div class="p-6 space-y-5">


                            {{-- TOPIK --}}

                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">
                                    Topik Pembelajaran
                                </label>

                                <input type="text"
                                       value="{{ $topic->title }}"
                                       readonly
                                       class="block w-full border border-gray-300 bg-gray-50 rounded-md shadow-sm text-sm p-2.5 text-gray-600">

                            </div>


                            {{-- JENIS AKTIVITAS --}}

                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                    Jenis Aktivitas

                                    <span class="text-red-500">*</span>

                                </label>

                                <select name="assignment_type"
                                        id="assignment_type"
                                        required
                                        class="block w-full border border-gray-300 rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                    <option value="assignment"
                                        @selected(old('assignment_type', 'assignment') === 'assignment')>
                                        Tugas
                                    </option>

                                    <option value="cbt"
                                        @selected(old('assignment_type') === 'cbt')>
                                        CBT / Ujian
                                    </option>

                                </select>

                                @error('assignment_type')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror

                            </div>


                            {{-- JUDUL AKTIVITAS --}}

                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                    Judul Aktivitas

                                    <span class="text-red-500">*</span>

                                </label>

                                <input type="text"
                                       name="title"
                                       value="{{ old('title') }}"
                                       placeholder="Contoh: Tugas Pengamatan Gerak"
                                       required
                                       autofocus
                                       class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('title') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">

                                @error('title')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror

                            </div>


                            {{-- INSTRUKSI --}}

                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                    Instruksi

                                </label>

                                <textarea name="instructions"
                                          rows="7"
                                          placeholder="Tuliskan petunjuk atau instruksi pengerjaan..."
                                          class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('instructions') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">{{ old('instructions') }}</textarea>

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

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">

                        <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100">

                            <h3 class="font-bold text-indigo-900">
                                ⚙️ Pengaturan Aktivitas
                            </h3>

                            <p class="text-xs text-indigo-500 mt-0.5">
                                Atur nilai, bentuk pengumpulan, dan batas waktu.
                            </p>

                        </div>


                        <div class="p-6 space-y-5">


                            {{-- =================================================
                                 PENGATURAN TUGAS BIASA
                                 ================================================= --}}

                            <div id="assignment-settings">


                                {{-- NILAI + TIPE PENGUMPULAN --}}

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">


                                    {{-- NILAI MAKSIMAL --}}

                                    <div>

                                        <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                            Nilai Maksimal

                                            <span class="text-red-500">*</span>

                                        </label>

                                        <input type="number"
                                               name="max_score"
                                               id="max_score"
                                               value="{{ old('max_score', 100) }}"
                                               min="0"
                                               max="100"
                                               step="0.01"
                                               class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('max_score') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">

                                        @error('max_score')

                                            <p class="text-xs text-red-600 mt-1">
                                                {{ $message }}
                                            </p>

                                        @enderror

                                    </div>


                                    {{-- JENIS PENGUMPULAN --}}

                                    <div>

                                        <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                            Bentuk Pengumpulan

                                            <span class="text-red-500">*</span>

                                        </label>

                                        <select name="submission_type"
                                                id="submission_type"
                                                class="block w-full border border-gray-300 rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                            <option value="text"
                                                @selected(old('submission_type') === 'text')>
                                                Teks
                                            </option>

                                            <option value="file"
                                                @selected(old('submission_type') === 'file')>
                                                File
                                            </option>

                                            <option value="text_file"
                                                @selected(old('submission_type', 'text_file') === 'text_file')>
                                                Teks / File
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

                                <div class="mt-5">

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Batas Pengumpulan

                                    </label>

                                    <input type="datetime-local"
                                           name="due_at"
                                           value="{{ old('due_at') }}"
                                           class="block w-full border border-gray-300 rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                    @error('due_at')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                    <p class="text-xs text-gray-400 mt-1.5">

                                        Kosongkan jika tugas tidak memiliki batas waktu.

                                    </p>

                                </div>

                            </div>



                            {{-- =================================================
                                 PENGATURAN CBT
                                 ================================================= --}}

                            <div id="cbt-settings"
                                 class="hidden space-y-5">


                                {{-- PILIH UJIAN --}}

                                <div>

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Ujian CBT

                                        <span class="text-red-500">*</span>

                                    </label>

                                    <select name="exam_id"
                                            id="exam_id"
                                            class="block w-full border border-gray-300 rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                        <option value="">
                                            -- Pilih Ujian CBT --
                                        </option>

                                        @foreach ($exams as $exam)

                                            <option value="{{ $exam->id }}"
                                                    data-duration="{{ $exam->durasi_menit }}"
                                                    data-start="{{ $exam->waktu_mulai }}"
                                                    data-end="{{ $exam->waktu_selesai }}"
                                                    @selected(old('exam_id') == $exam->id)>

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
                                     class="hidden bg-indigo-50 border border-indigo-100 rounded-lg p-4">

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">


                                        {{-- DURASI --}}

                                        <div>

                                            <p class="text-xs text-gray-500">
                                                Durasi
                                            </p>

                                            <p id="exam-duration"
                                               class="font-bold text-indigo-900 mt-1">
                                                -
                                            </p>

                                        </div>


                                        {{-- WAKTU MULAI --}}

                                        <div>

                                            <p class="text-xs text-gray-500">
                                                Waktu Mulai
                                            </p>

                                            <p id="exam-start"
                                               class="font-bold text-indigo-900 mt-1">
                                                -
                                            </p>

                                        </div>


                                        {{-- WAKTU SELESAI --}}

                                        <div>

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

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Nilai Maksimal

                                        <span class="text-red-500">*</span>

                                    </label>

                                    <input type="number"
                                           name="cbt_max_score"
                                           id="cbt_max_score"
                                           value="{{ old('cbt_max_score', 100) }}"
                                           min="0"
                                           max="100"
                                           step="0.01"
                                           class="block w-full border border-gray-300 rounded-md shadow-sm text-sm p-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                    <p class="text-xs text-gray-400 mt-1.5">

                                        Nilai maksimal yang digunakan untuk aktivitas CBT.

                                    </p>

                                </div>


                            </div>



                            {{-- =================================================
                                 STATUS PUBLIKASI
                                 ================================================= --}}

                            <div class="border border-gray-200 rounded-lg bg-gray-50 px-4 py-4">

                                <div class="flex items-start gap-3">

                                    <input type="hidden"
                                           name="is_published"
                                           value="0">

                                    <input type="checkbox"
                                           id="is_published"
                                           name="is_published"
                                           value="1"
                                           @checked(old('is_published'))
                                           class="mt-1 h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">

                                    <div>

                                        <label for="is_published"
                                               class="text-sm font-bold text-gray-700 cursor-pointer">

                                            Publikasikan aktivitas

                                        </label>

                                        <p class="text-xs text-gray-500 mt-0.5">

                                            Jika diaktifkan, aktivitas dapat dilihat dan dikerjakan oleh murid.

                                        </p>

                                    </div>

                                </div>

                            </div>


                        </div>

                    </div>



                    {{-- =================================================
                         TOMBOL AKSI
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 px-6 py-4 flex justify-between items-center flex-wrap gap-3">

                        <a href="{{ route('lms.courses.show', $topic->course) }}"
                           class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 border border-gray-200 px-5 py-2.5 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold">

                            ← Batal

                        </a>


                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-2.5 rounded-lg hover:bg-indigo-700 transition-colors text-sm font-bold shadow-sm">

                            💾 Simpan Aktivitas

                        </button>

                    </div>


                </form>

            </div>



            {{-- =================================================
                 SIDEBAR
                 ================================================= --}}

            <div class="space-y-6">


                {{-- INFORMASI TOPIK --}}

                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 p-6 transition-all duration-300 hover:shadow-lg">

                    <h4 class="font-bold text-gray-800 mb-3">

                        📚 Topik Pembelajaran

                    </h4>

                    <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4">

                        <p class="font-bold text-indigo-900">

                            {{ $topic->title }}

                        </p>

                        @if($topic->description)

                            <p class="text-sm text-gray-500 mt-2">

                                {{ $topic->description }}

                            </p>

                        @endif

                    </div>

                </div>



                {{-- INFORMASI CBT --}}

                <div id="cbt-help"
                     class="hidden bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 p-6 transition-all duration-300 hover:shadow-lg">

                    <h4 class="font-bold text-gray-800 mb-3">

                        📝 Aktivitas CBT

                    </h4>

                    <div class="bg-indigo-50 border border-indigo-100 rounded-lg p-4">

                        <p class="text-sm text-gray-600">

                            Aktivitas CBT terhubung langsung dengan ujian yang sudah dibuat pada modul CBT.

                        </p>

                        <p class="text-sm text-gray-600 mt-2">

                            Murid nantinya mengerjakan ujian melalui aktivitas ini.

                        </p>

                    </div>

                </div>



                {{-- TIPS --}}

                <div id="assignment-help"
                     class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 p-6 transition-all duration-300 hover:shadow-lg">

                    <h4 class="font-bold text-gray-800 mb-3">

                        💡 Tips Membuat Tugas

                    </h4>

                    <ul class="space-y-2.5 text-sm text-gray-500">

                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ◆
                            </span>

                            Gunakan judul tugas yang singkat dan jelas.

                        </li>

                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ◆
                            </span>

                            Tuliskan instruksi agar murid memahami apa yang harus dikerjakan.

                        </li>

                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ◆
                            </span>

                            Tentukan nilai maksimal sesuai sistem penilaian.

                        </li>

                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ◆
                            </span>

                            Gunakan batas waktu jika tugas harus dikumpulkan sebelum waktu tertentu.

                        </li>

                    </ul>

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

        const typeSelect = document.getElementById('assignment_type');

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
