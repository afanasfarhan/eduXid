<x-guest-layout>
    <div class="w-full max-w-2xl">
        <div class="mb-6 text-center">
            <h1 class="text-2xl font-bold text-gray-900">
                Pilih Tahun Ajaran &amp; Semester
            </h1>

            <p class="mt-2 text-sm text-gray-600">
                Selamat datang, {{ auth()->user()->name }}.
                Pilih periode data yang ingin dibuka.
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        @if (empty($periods))
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5">
                <h2 class="font-semibold text-amber-900">
                    Belum ada periode dari Data Pokok
                </h2>

                <p class="mt-2 text-sm text-amber-800">
                    Tahun ajaran/semester akan tersedia setelah data Rombel
                    disinkronkan dari Dapodik atau Data Pokok.
                </p>

                <div class="mt-5">
                    <a
                        href="{{ route('dashboard') }}"
                        class="inline-flex items-center rounded-lg bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700">
                        Kembali ke Dashboard
                    </a>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('pilih.semester.store') }}">
                @csrf

                <div class="space-y-3">
                    @foreach ($periods as $period)
                        @php
                            $checked = old(
                                'semester_id',
                                $defaultSemesterId
                            ) === $period['id'];
                        @endphp

                        <label
                            class="block cursor-pointer rounded-xl border p-4 transition
                                {{ $checked
                                    ? 'border-indigo-500 bg-indigo-50 ring-1 ring-indigo-500'
                                    : 'border-gray-200 bg-white hover:border-indigo-300' }}">
                            <div class="flex items-start gap-3">
                                <input
                                    type="radio"
                                    name="semester_id"
                                    value="{{ $period['id'] }}"
                                    class="mt-1 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                    {{ $checked ? 'checked' : '' }}>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-gray-900">
                                            Tahun Ajaran {{ $period['academic_year'] }}
                                        </span>

                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                            {{ $period['semester'] }}
                                        </span>

                                        @if ($period['is_latest'])
                                            <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700">
                                                Terbaru dari Data Pokok
                                            </span>
                                        @endif

                                        @if ($currentSemesterId === $period['id'])
                                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-700">
                                                Sedang dibuka
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-1 text-xs text-gray-500">
                                        Semester ID Dapodik: {{ $period['id'] }}
                                    </div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>

                <div class="mt-6 rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    @if($isGtk)
                        Pilihan ini hanya berlaku untuk sesi GTK Anda.
                        Membuka semester lampau tidak mengubah periode pengguna lain
                        dan tidak menghapus data semester lainnya.
                    @else
                        Pilihan periode hanya mengubah tampilan data pada sesi Anda.
                        Data periode lain tetap tersimpan.
                    @endif
                </div>

                <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                    @if($currentSemesterId)
                        <a
                            href="{{ route('dashboard') }}"
                            class="text-sm font-semibold text-gray-600 hover:text-gray-900">
                            Kembali tanpa mengubah
                        </a>
                    @else
                        <span></span>
                    @endif

                    <button
                        type="submit"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        Buka Periode
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-guest-layout>
