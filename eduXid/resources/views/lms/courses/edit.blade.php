<x-app-layout>

<x-slot name="header">

    <div class="flex items-center justify-between flex-wrap gap-3">

        <div>

            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Edit Kelas LMS
            </h2>

            <p class="text-sm text-gray-500 mt-0.5">
                Perbarui informasi kelas pembelajaran.
            </p>

        </div>

        <a href="{{ route('lms.courses.show', $course) }}"
           class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">

            ← Kembali ke Kelas

        </a>

    </div>

</x-slot>


<div class="py-12">

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">


        {{-- =====================================================
             PESAN SESSION
             ===================================================== --}}

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
                 FORM EDIT
                 ================================================= --}}

            <div class="lg:col-span-2">

                <form method="POST"
                      action="{{ route('lms.courses.update', $course) }}"
                      class="space-y-6">

                    @csrf
                    @method('PUT')


                    {{-- =================================================
                         SECTION 1
                         INFORMASI KELAS
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">


                        {{-- HEADER SECTION --}}

                        <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100">

                            <h3 class="font-bold text-indigo-900">
                                📚 Informasi Kelas
                            </h3>

                            <p class="text-xs text-indigo-500 mt-0.5">
                                Informasi utama kelas pembelajaran
                            </p>

                        </div>


                        {{-- BODY SECTION --}}

                        <div class="p-6 space-y-4">


                            {{-- =================================================
                                 NAMA KELAS
                                 ================================================= --}}

                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                    Nama Kelas LMS

                                    <span class="text-red-500">
                                        *
                                    </span>

                                </label>


                                <input type="text"
                                       name="name"
                                       value="{{ old('name', $course->name) }}"
                                       placeholder="Contoh: Matematika X MERDEKA 1"
                                       required
                                       class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('name') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">


                                @error('name')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror


                                <p class="text-xs text-gray-400 mt-1.5">
                                    Nama kelas yang akan ditampilkan kepada guru dan murid.
                                </p>

                            </div>


                            {{-- =================================================
                                 MATA PELAJARAN + ROMBEL
                                 ================================================= --}}

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">


                                {{-- MATA PELAJARAN --}}

                                <div>

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Mata Pelajaran

                                        <span class="text-red-500">
                                            *
                                        </span>

                                    </label>


                                    <select name="subject_id"
                                            required
                                            class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('subject_id') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">


                                        <option value="">
                                            -- Pilih Mata Pelajaran --
                                        </option>


                                        @foreach ($subjects as $subject)

                                            <option value="{{ $subject->id }}"
                                                @selected(
                                                    old(
                                                        'subject_id',
                                                        $course->subject_id
                                                    ) == $subject->id
                                                )>

                                                {{ $subject->nama_mata_pelajaran }}

                                            </option>

                                        @endforeach


                                    </select>


                                    @if ($subjects->isEmpty())

                                        <p class="text-xs text-red-600 mt-1">

                                            Tidak ada mata pelajaran yang tersedia
                                            sebagai penugasan Anda.

                                        </p>

                                    @endif


                                    @error('subject_id')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>


                                {{-- ROMBEL --}}

                                <div>

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Kelas / Rombel

                                        <span class="text-red-500">
                                            *
                                        </span>

                                    </label>


                                    <select name="homeroom_id"
                                            required
                                            class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('homeroom_id') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">


                                        <option value="">
                                            -- Pilih Kelas / Rombel --
                                        </option>


                                        @foreach ($homerooms as $homeroom)

                                            <option value="{{ $homeroom->id }}"
                                                @selected(
                                                    old(
                                                        'homeroom_id',
                                                        $course->homeroom_id
                                                    ) == $homeroom->id
                                                )>

                                                {{ $homeroom->nama }}

                                            </option>

                                        @endforeach


                                    </select>


                                    @error('homeroom_id')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>


                            </div>


                        </div>

                    </div>


                    {{-- =================================================
                         SECTION 2
                         DESKRIPSI
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">


                        {{-- HEADER --}}

                        <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100">

                            <h3 class="font-bold text-indigo-900">
                                📝 Deskripsi
                            </h3>

                            <p class="text-xs text-indigo-500 mt-0.5">
                                Detail tambahan kelas pembelajaran
                            </p>

                        </div>


                        {{-- BODY --}}

                        <div class="p-6">


                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                    Deskripsi

                                </label>


                                <textarea name="description"
                                          rows="5"
                                          placeholder="Deskripsi singkat kelas pembelajaran..."
                                          class="block w-full border rounded-md shadow-sm text-sm p-2.5 transition-colors focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none {{ $errors->has('description') ? 'border-red-500 bg-red-50' : 'border-gray-300' }}">{{ old('description', $course->description) }}</textarea>


                                @error('description')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror


                                <p class="text-xs text-gray-400 mt-1.5">

                                    Deskripsi membantu murid memahami fokus
                                    pembelajaran pada kelas ini.

                                </p>

                            </div>


                        </div>

                    </div>


                    {{-- =================================================
                         SECTION 3
                         STATUS KELAS
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden">


                        {{-- HEADER --}}

                        <div class="bg-indigo-50 px-6 py-4 border-b border-indigo-100">

                            <h3 class="font-bold text-indigo-900">
                                ⚙️ Status Kelas
                            </h3>

                            <p class="text-xs text-indigo-500 mt-0.5">
                                Atur ketersediaan kelas untuk murid
                            </p>

                        </div>


                        {{-- BODY --}}

                        <div class="p-6">


                            <div class="bg-gray-50 border border-gray-200 rounded-lg px-4 py-4">


                                <div class="flex items-start gap-3">


                                    {{-- Hidden value untuk status OFF --}}

                                    <input type="hidden"
                                           name="is_published"
                                           value="0">


                                    <input type="checkbox"
                                           id="is_published"
                                           name="is_published"
                                           value="1"
                                           @checked(
                                               old(
                                                   'is_published',
                                                   $course->is_published
                                               )
                                           )
                                           class="mt-1 w-5 h-5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">


                                    <div>

                                        <label for="is_published"
                                               class="font-bold text-gray-800 cursor-pointer">

                                            Publikasikan kelas

                                        </label>


                                        <p class="text-sm text-gray-500 mt-1">

                                            Jika diaktifkan, kelas dapat ditampilkan
                                            sebagai kelas yang tersedia untuk murid.

                                        </p>


                                        @if ($course->is_published)

                                            <span class="inline-flex items-center mt-2 bg-green-50 text-green-700 border border-green-200 px-3 py-1 rounded-full text-xs font-bold">

                                                Dipublikasikan

                                            </span>

                                        @else

                                            <span class="inline-flex items-center mt-2 bg-gray-100 text-gray-600 border border-gray-200 px-3 py-1 rounded-full text-xs font-bold">

                                                Draft

                                            </span>

                                        @endif


                                    </div>

                                </div>


                            </div>


                        </div>

                    </div>


                    {{-- =================================================
                         TOMBOL AKSI
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 px-6 py-4 flex justify-between items-center flex-wrap gap-3">


                        <a href="{{ route('lms.courses.show', $course) }}"
                           class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 border border-gray-200 px-5 py-2.5 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold">

                            ← Batal

                        </a>


                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-indigo-600 text-white px-6 py-2.5 rounded-lg hover:bg-indigo-700 transition-colors text-sm font-bold shadow-sm">

                            💾 Simpan Perubahan

                        </button>


                    </div>


                </form>

            </div>


            {{-- =================================================
                 SIDEBAR
                 ================================================= --}}

            <div class="space-y-6">


                {{-- =================================================
                     INFORMASI KELAS SAAT INI
                     ================================================= --}}

                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 p-6 transition-all duration-300 hover:shadow-lg">


                    <h4 class="font-bold text-gray-800 mb-4">
                        📋 Informasi Kelas
                    </h4>


                    <div class="space-y-3">


                        {{-- NAMA --}}

                        <div>

                            <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">
                                Nama Kelas
                            </p>

                            <p class="text-sm font-bold text-gray-800 mt-0.5">
                                {{ $course->name }}
                            </p>

                        </div>


                        {{-- MAPEL --}}

                        @if ($course->subject)

                            <div>

                                <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">
                                    Mata Pelajaran
                                </p>

                                <p class="text-sm font-bold text-indigo-700 mt-0.5">
                                    {{ $course->subject->nama_mata_pelajaran }}
                                </p>

                            </div>

                        @endif


                        {{-- ROMBEL --}}

                        @if ($course->homeroom)

                            <div>

                                <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">
                                    Kelas / Rombel
                                </p>

                                <span class="inline-flex items-center mt-1 bg-indigo-50 text-indigo-600 border border-indigo-200 px-3 py-1 rounded-full text-xs font-bold">

                                    {{ $course->homeroom->nama }}

                                </span>

                            </div>

                        @endif


                        {{-- TOPIK --}}

                        <div>

                            <p class="text-xs text-gray-400 uppercase tracking-wider font-bold">
                                Topik Pembelajaran
                            </p>

                            <span class="inline-flex items-center mt-1 bg-indigo-50 text-indigo-600 border border-indigo-200 px-3 py-1 rounded-full text-xs font-bold">

                                {{ $course->topics->count() }} topik

                            </span>

                        </div>


                    </div>


                </div>


                {{-- =================================================
                     TIPS
                     ================================================= --}}

                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 p-6 transition-all duration-300 hover:shadow-lg">


                    <h4 class="font-bold text-gray-800 mb-3">
                        💡 Tips Mengedit Kelas
                    </h4>


                    <ul class="space-y-2.5 text-sm text-gray-500">


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Pastikan mata pelajaran dan kelas/rombel
                            sesuai dengan penugasan Anda.

                        </li>


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Gunakan nama kelas yang jelas,
                            misalnya
                            <span class="font-semibold text-gray-700">
                                Matematika X MERDEKA 1
                            </span>.

                        </li>


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Publikasikan kelas jika sudah siap
                            digunakan oleh murid.

                        </li>


                    </ul>


                </div>


            </div>


        </div>

    </div>

</div>

</x-app-layout>
