<x-app-layout>

<x-slot name="header">

    <div class="flex items-center justify-between gap-3 flex-wrap">

        <div class="min-w-0">

            <p class="text-xs text-indigo-500 font-bold uppercase tracking-wide">
                Gandakan Kelas LMS
            </p>

            <h2 class="text-xl font-bold text-gray-800 truncate">
                {{ $course->name }}
            </h2>

            <p class="text-sm text-gray-500 mt-1">
                {{ $course->subject->nama_mata_pelajaran ?? 'Mata Pelajaran' }}
                •
                {{ $course->homeroom->nama ?? 'Rombel' }}
            </p>

        </div>

        <a
            href="{{ route('lms.courses.show', $course) }}"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg
                   bg-gray-100 hover:bg-gray-200 text-gray-700
                   text-sm font-semibold"
        >
            ← Kembali
        </a>

    </div>

</x-slot>


<div class="py-6">

    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

        @if(session('error'))

            <div class="mb-5 rounded-xl border border-red-200 bg-red-50
                        px-4 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>

        @endif


        @if(session('warning'))

            <div class="mb-5 rounded-xl border border-yellow-200 bg-yellow-50
                        px-4 py-3 text-sm text-yellow-800">
                {{ session('warning') }}
            </div>

        @endif


        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

            <div class="p-6">

                <div class="mb-6">

                    <h3 class="text-lg font-bold text-gray-800">
                        Gandakan kelas ke rombel lain
                    </h3>

                    <p class="text-sm text-gray-500 mt-1">
                        Seluruh struktur pembelajaran akan disalin ke rombel tujuan.
                    </p>

                </div>


                <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-4 mb-6">

                    <p class="text-sm font-semibold text-indigo-800 mb-2">
                        Yang akan ikut disalin
                    </p>

                    <ul class="text-sm text-indigo-700 space-y-1">

                        <li>✓ Topik pembelajaran</li>
                        <li>✓ Materi dan isi materi</li>
                        <li>✓ Rumus matematika/KaTeX</li>
                        <li>✓ File PDF/dokumen/media</li>
                        <li>✓ Tugas/aktivitas</li>
                        <li>✓ Konfigurasi CBT</li>
                        <li>✓ Daftar soal CBT</li>

                    </ul>

                </div>


                <div class="rounded-xl bg-gray-50 border border-gray-200 p-4 mb-6">

                    <p class="text-sm font-semibold text-gray-700 mb-2">
                        Yang tidak ikut disalin
                    </p>

                    <ul class="text-sm text-gray-600 space-y-1">

                        <li>• Jawaban/submission murid</li>
                        <li>• Nilai murid</li>
                        <li>• Progres murid</li>
                        <li>• Feedback guru kepada murid</li>
                        <li>• Session ujian murid</li>

                    </ul>

                </div>


                @if($targetHomerooms->count())

                    <form
                        method="POST"
                        action="{{ route('lms.courses.duplicate', $course) }}"
                    >

                        @csrf


                        <div class="mb-6">

                            <label
                                for="homeroom_id"
                                class="block text-sm font-semibold text-gray-700 mb-2"
                            >
                                Pilih Rombel Tujuan
                            </label>

                            <select
                                name="homeroom_id"
                                id="homeroom_id"
                                required
                                class="w-full rounded-xl border-gray-300
                                       focus:border-indigo-500
                                       focus:ring-indigo-500"
                            >

                                <option value="">
                                    — Pilih Rombel —
                                </option>

                                @foreach($targetHomerooms as $homeroom)

                                    <option
                                        value="{{ $homeroom->id }}"
                                        @selected(
                                            old('homeroom_id')
                                            == $homeroom->id
                                        )
                                    >
                                        {{ $homeroom->nama }}
                                    </option>

                                @endforeach

                            </select>

                            @error('homeroom_id')

                                <p class="mt-2 text-sm text-red-600">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>


                        <div class="flex items-center justify-end gap-3">

                            <a
                                href="{{ route('lms.courses.show', $course) }}"
                                class="px-5 py-2.5 rounded-xl
                                       bg-gray-100 hover:bg-gray-200
                                       text-gray-700 text-sm font-semibold"
                            >
                                Batal
                            </a>


                            <button
                                type="submit"
                                class="px-5 py-2.5 rounded-xl
                                       bg-indigo-600 hover:bg-indigo-700
                                       text-white text-sm font-semibold
                                       shadow-sm"
                                onclick="
                                    return confirm(
                                        'Gandakan kelas ini ke rombel yang dipilih?'
                                    );
                                "
                            >
                                Gandakan Kelas
                            </button>

                        </div>

                    </form>

                @else

                    <div class="rounded-xl border border-yellow-200
                                bg-yellow-50 p-5">

                        <p class="font-semibold text-yellow-800">
                            Tidak ada rombel tujuan yang tersedia.
                        </p>

                        <p class="text-sm text-yellow-700 mt-1">
                            Semua rombel yang Anda ajar untuk mata pelajaran ini
                            mungkin sudah memiliki Kelas LMS.
                        </p>

                    </div>

                @endif

            </div>

        </div>

    </div>

</div>

</x-app-layout>
