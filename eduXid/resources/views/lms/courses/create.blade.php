<x-app-layout>
<x-slot name="header">

    <div class="flex items-center justify-between flex-wrap gap-3">

        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Buat Kelas LMS
            </h2>

            <p class="text-sm text-gray-500 mt-0.5">
                Buat ruang pembelajaran untuk murid.
            </p>
        </div>

        <a href="{{ route('lms.courses.index') }}"
           class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">
            ← Kembali
        </a>

    </div>

</x-slot>


<div class="py-12">

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">


        {{-- =====================================================
             PESAN
             ===================================================== --}}

        @if (session('error'))

            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                {{ session('error') }}
            </div>

        @endif


        @if ($errors->any())

            <div class="bg-red-50 border border-red-500 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">

                <p class="font-bold mb-1">
                    ⚠️ Periksa data berikut:
                </p>

                <ul class="list-disc list-inside text-sm space-y-0.5">

                    @foreach ($errors->all() as $error)

                        <li>
                            {{ $error }}
                        </li>

                    @endforeach

                </ul>

            </div>

        @endif


        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">


            {{-- =================================================
                 FORM
                 ================================================= --}}

            <div class="lg:col-span-2">

                <form method="POST"
                      action="{{ route('lms.courses.store') }}"
                      class="space-y-6">

                    @csrf


                    {{-- =================================================
                         INFORMASI KELAS
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100">


                        {{-- Header Section --}}

                        <div class="bg-indigo-50 p-4 border-b border-indigo-100">

                            <h3 class="text-lg font-bold text-indigo-900 leading-tight">
                                📚 Informasi Kelas
                            </h3>

                            <p class="text-xs text-indigo-500 mt-1">
                                Tentukan mata pelajaran dan kelas/rombel.
                            </p>

                        </div>


                        {{-- Body Section --}}

                        <div class="p-4 space-y-5">


                            {{-- =================================================
                                 MAPEL + ROMBEL
                                 ================================================= --}}

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">


                                {{-- Mata Pelajaran --}}

                                <div>

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Mata Pelajaran

                                        <span class="text-red-500">*</span>

                                    </label>


                                    <select name="subject_id"
                                            id="subject_id"
                                            required
                                            class="block w-full border border-gray-300 rounded-lg shadow-sm text-sm p-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                        <option value="">
                                            -- Pilih Mata Pelajaran --
                                        </option>


                                        @foreach ($subjects as $subject)

                                            <option value="{{ $subject->id }}"
                                                @selected(old('subject_id') == $subject->id)>

                                                {{ $subject->nama_mata_pelajaran }}

                                            </option>

                                        @endforeach

                                    </select>


                                    @if($subjects->isEmpty())

                                        <p class="text-xs text-red-600 mt-1">

                                            Tidak ada mata pelajaran yang terdaftar sebagai penugasan Anda.

                                        </p>

                                    @endif


                                    @error('subject_id')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>


                                {{-- Kelas / Rombel --}}

                                <div>

                                    <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                        Kelas / Rombel

                                        <span class="text-red-500">*</span>

                                    </label>


                                    <select name="homeroom_id"
                                            id="homeroom_id"
                                            required
                                            disabled
                                            class="block w-full border border-gray-300 rounded-lg shadow-sm text-sm p-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">

                                        <option value="">
                                            -- Pilih Mata Pelajaran Terlebih Dahulu --
                                        </option>

                                    </select>


                                    @error('homeroom_id')

                                        <p class="text-xs text-red-600 mt-1">
                                            {{ $message }}
                                        </p>

                                    @enderror

                                </div>

                            </div>


                            {{-- =================================================
                                 NAMA KELAS LMS
                                 ================================================= --}}

                            <div>

                                <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                    Nama Kelas LMS

                                    <span class="text-red-500">*</span>

                                </label>


                                <input type="text"
                                       name="name"
                                       value="{{ old('name') }}"
                                       placeholder="Contoh: Penjaskes X MERDEKA 1"
                                       required
                                       class="block w-full border border-gray-300 rounded-lg shadow-sm text-sm p-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">


                                @error('name')

                                    <p class="text-xs text-red-600 mt-1">
                                        {{ $message }}
                                    </p>

                                @enderror


                                <p class="text-xs text-gray-400 mt-1.5">

                                    Nama kelas yang akan ditampilkan kepada guru dan murid.

                                </p>

                            </div>


                        </div>

                    </div>


                    {{-- =================================================
                         DESKRIPSI
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100">


                        {{-- Header Section --}}

                        <div class="bg-indigo-50 p-4 border-b border-indigo-100">

                            <h3 class="text-lg font-bold text-indigo-900 leading-tight">
                                📝 Deskripsi
                            </h3>

                            <p class="text-xs text-indigo-500 mt-1">
                                Tambahkan informasi singkat mengenai kelas.
                            </p>

                        </div>


                        {{-- Body Section --}}

                        <div class="p-4">


                            <label class="block text-sm font-bold text-gray-700 mb-1.5">

                                Deskripsi

                            </label>


                            <textarea name="description"
                                      rows="4"
                                      placeholder="Deskripsi singkat kelas pembelajaran..."
                                      class="block w-full border border-gray-300 rounded-lg shadow-sm text-sm p-2.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 focus:outline-none">{{ old('description') }}</textarea>


                            @error('description')

                                <p class="text-xs text-red-600 mt-1">
                                    {{ $message }}
                                </p>

                            @enderror


                        </div>

                    </div>


                    {{-- =================================================
                         STATUS PUBLIKASI          ← TAMBAHAN BARU
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100">


                        {{-- Header Section --}}

                        <div class="bg-indigo-50 p-4 border-b border-indigo-100">

                            <h3 class="text-lg font-bold text-indigo-900 leading-tight">
                                🚀 Status Publikasi
                            </h3>

                            <p class="text-xs text-indigo-500 mt-1">
                                Tentukan apakah kelas langsung terlihat oleh murid.
                            </p>

                        </div>


                        {{-- Body Section --}}

                        <div class="p-4">


                            <label class="flex items-start gap-3
                                          bg-gray-50 border border-gray-200
                                          rounded-xl px-4 py-3.5 cursor-pointer
                                          hover:bg-indigo-50
                                          hover:border-indigo-200
                                          transition-colors">

                                <input
                                    type="checkbox"
                                    name="is_published"
                                    value="1"
                                    @checked(old('is_published'))
                                    class="mt-0.5 w-4 h-4 rounded
                                           border-gray-300
                                           text-indigo-600
                                           focus:ring-indigo-500
                                           focus:ring-offset-0"
                                >

                                <span class="text-sm font-semibold text-gray-700">

                                    Publikasikan kelas ini sekarang

                                    <span class="block text-xs font-normal
                                                 text-gray-400 mt-0.5
                                                 leading-relaxed">

                                        Kelas langsung terlihat oleh murid pada
                                        perangkat mereka setelah tersinkronisasi.
                                        Bila tidak dicentang, kelas tersimpan
                                        sebagai draft dan dapat dipublikasikan
                                        kapan saja dari halaman kelas.

                                    </span>

                                </span>

                            </label>


                            @error('is_published')

                                <p class="text-xs text-red-600 mt-1">
                                    {{ $message }}
                                </p>

                            @enderror


                        </div>

                    </div>


                    {{-- =================================================
                         TOMBOL AKSI
                         ================================================= --}}

                    <div class="bg-white rounded-xl shadow-md border border-gray-100 px-4 py-4 flex justify-between items-center flex-wrap gap-3">


                        <a href="{{ route('lms.courses.index') }}"
                           class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 border border-gray-200 px-5 py-2.5 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold">

                            ← Batal

                        </a>


                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-purple-50 text-purple-700 border border-purple-100 px-6 py-2.5 rounded-lg hover:bg-purple-100 transition-colors text-sm font-bold">

                            💾 Simpan Kelas

                        </button>


                    </div>


                </form>

            </div>


            {{-- =================================================
                 SIDEBAR INFO
                 ================================================= --}}

            <div class="space-y-6">


                {{-- Tips --}}

                <div class="bg-white rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 p-6 transition-all duration-300 hover:shadow-lg">

                    <h4 class="font-bold text-gray-800 mb-3">

                        💡 Tips Membuat Kelas

                    </h4>


                    <ul class="space-y-2.5 text-sm text-gray-500">


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Pilih mata pelajaran dan kelas yang akan dibuat kelas LMS.

                        </li>


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Gunakan nama kelas yang jelas, misalnya

                            <span class="font-semibold text-gray-700">
                                "Penjaskes X MERDEKA 1"
                            </span>.

                        </li>


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Isi deskripsi singkat agar murid memahami fokus kelas ini.

                        </li>


                        <li class="flex items-start gap-2">

                            <span class="text-indigo-400 mt-0.5">
                                ▸
                            </span>

                            Centang

                            <span class="font-semibold text-gray-700">
                                "Publikasikan kelas ini sekarang"
                            </span>

                            agar kelas langsung terlihat oleh murid.

                        </li>


                    </ul>

                </div>


            </div>


        </div>

    </div>

</div>


{{-- =============================================================
     DATA PENUGASAN GURU
     ============================================================= --}}

<script>

    document.addEventListener('DOMContentLoaded', function () {


        const subjectSelect = document.getElementById('subject_id');

        const homeroomSelect = document.getElementById('homeroom_id');


        /*
         * Semua pasangan mapel + rombel yang merupakan
         * penugasan guru yang sedang login.
         */

        const teachingPairs = @json($teachingPairs);


        /*
         * Data nama rombel dari Controller.
         */

        const homerooms = @json(
            $homerooms->map(function ($homeroom) {

                return [

                    'id' => $homeroom->id,

                    'name' => $homeroom->nama,

                ];

            })->values()
        );


        const oldHomeroomId = @json(old('homeroom_id'));


        function updateHomerooms() {


            const subjectId = subjectSelect.value;


            homeroomSelect.innerHTML = '';


            if (!subjectId) {


                homeroomSelect.disabled = true;


                const option = document.createElement('option');


                option.value = '';

                option.textContent =
                    '-- Pilih Mata Pelajaran Terlebih Dahulu --';


                homeroomSelect.appendChild(option);


                return;

            }


            /*
             * Cari hanya rombel yang memiliki pasangan
             * subject_id + homeroom_id di teaching_assignments.
             */

            const allowedHomeroomIds = teachingPairs

                .filter(pair =>
                    String(pair.subject_id) === String(subjectId)
                )

                .map(pair =>
                    String(pair.homeroom_id)
                );


            /*
             * Hilangkan duplikasi ID rombel.
             */

            const uniqueHomeroomIds =
                [...new Set(allowedHomeroomIds)];


            if (uniqueHomeroomIds.length === 0) {


                homeroomSelect.disabled = true;


                const option = document.createElement('option');


                option.value = '';

                option.textContent =
                    '-- Tidak ada rombel untuk mata pelajaran ini --';


                homeroomSelect.appendChild(option);


                return;

            }


            homeroomSelect.disabled = false;


            const placeholder =
                document.createElement('option');


            placeholder.value = '';

            placeholder.textContent =
                '-- Pilih Kelas --';


            homeroomSelect.appendChild(placeholder);


            homerooms

                .filter(homeroom =>
                    uniqueHomeroomIds.includes(
                        String(homeroom.id)
                    )
                )

                .forEach(homeroom => {


                    const option =
                        document.createElement('option');


                    option.value = homeroom.id;

                    option.textContent = homeroom.name;


                    if (

                        oldHomeroomId !== null &&

                        String(oldHomeroomId) ===
                        String(homeroom.id)

                    ) {

                        option.selected = true;

                    }


                    homeroomSelect.appendChild(option);


                });


        }


        subjectSelect.addEventListener('change', function () {

            /*
             * Jika guru mengganti mapel, rombel lama
             * tidak boleh tetap terpilih.
             */

            updateHomerooms();

        });


        /*
         * Jalankan saat halaman pertama kali dibuka.
         *
         * Ini juga menjaga nilai lama setelah validasi gagal.
         */

        if (subjectSelect.value) {

            updateHomerooms();

        }


    });

</script>

</x-app-layout>