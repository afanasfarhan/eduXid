<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Profil Sekolah
                </h2>
                <p class="text-sm text-gray-500 mt-1">
                    Statistik sekolah, Data Pokok, dan ekspor profil dalam format Excel.
                </p>
            </div>

            <a href="{{ route('tu.profil.export') }}"
               class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg bg-emerald-600 text-white font-semibold hover:bg-emerald-700 shadow-sm">
                <span aria-hidden="true">⬇️</span>
                Unduh Profil Excel
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if(!$school)
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-800">
                    Data sekolah belum tersedia. Lakukan setup/impor Data Pokok terlebih dahulu.
                </div>
            @endif

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Peserta Didik</div>
                    <div class="mt-2 text-3xl font-bold text-gray-900">{{ $students->count() }}</div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Laki-laki</div>
                    <div class="mt-2 text-3xl font-bold text-blue-700">{{ $gender['L'] ?? 0 }}</div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Perempuan</div>
                    <div class="mt-2 text-3xl font-bold text-pink-700">{{ $gender['P'] ?? 0 }}</div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">GTK</div>
                    <div class="mt-2 text-3xl font-bold text-indigo-700">{{ $teacherStats['total'] ?? 0 }}</div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Rombel</div>
                    <div class="mt-2 text-3xl font-bold text-violet-700">{{ $homerooms->count() }}</div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">NPSN</div>
                    <div class="mt-2 text-xl font-bold text-gray-900 font-mono">{{ $school->npsn ?? '-' }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b">
                        <h3 class="font-bold text-gray-800">Identitas Sekolah</h3>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 p-5 text-sm">
                        @php
                            $identity = [
                                'Nama Sekolah' => $school->nama ?? '-',
                                'NPSN' => $school->npsn ?? '-',
                                'Jenjang' => $school->bentuk_pendidikan ?? '-',
                                'Status' => $school->status_sekolah ?? '-',
                                'Alamat' => $school->alamat_jalan ?? '-',
                                'Kecamatan' => $school->kecamatan ?? '-',
                                'Kabupaten/Kota' => $school->kabupaten_kota ?? '-',
                                'Provinsi' => $school->provinsi ?? '-',
                                'Kode Pos' => $school->kode_pos ?? '-',
                                'Telepon' => $school->nomor_telepon ?? '-',
                                'Email' => $school->email ?? '-',
                                'Website' => $school->website ?? '-',
                            ];
                        @endphp

                        @foreach($identity as $label => $value)
                            <div class="border-b border-gray-100 pb-3">
                                <div class="text-xs uppercase tracking-wide font-semibold text-gray-400">{{ $label }}</div>
                                <div class="mt-1 font-medium text-gray-800 break-words">{{ $value ?: '-' }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b">
                        <h3 class="font-bold text-gray-800">Komposisi GTK</h3>
                    </div>

                    <div class="p-5 space-y-4">
                        <div class="flex justify-between gap-4">
                            <span class="text-sm text-gray-600">Guru</span>
                            <span class="font-bold text-gray-900">{{ $teacherStats['guru'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-sm text-gray-600">Tenaga Kependidikan</span>
                            <span class="font-bold text-gray-900">{{ $teacherStats['tendik'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-sm text-gray-600">Laki-laki</span>
                            <span class="font-bold text-gray-900">{{ $teacherStats['laki_laki'] ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between gap-4">
                            <span class="text-sm text-gray-600">Perempuan</span>
                            <span class="font-bold text-gray-900">{{ $teacherStats['perempuan'] ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b">
                        <h3 class="font-bold text-gray-800">Peserta Didik per Tingkat</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-gray-600">
                                <tr>
                                    <th class="px-4 py-3 text-left">Tingkat</th>
                                    <th class="px-4 py-3 text-center">L</th>
                                    <th class="px-4 py-3 text-center">P</th>
                                    <th class="px-4 py-3 text-center">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                @foreach([10, 11, 12] as $level)
                                    @php
                                        $l = $levelStats[$level]['L'] ?? 0;
                                        $p = $levelStats[$level]['P'] ?? 0;
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-900">Tingkat {{ $level }}</td>
                                        <td class="px-4 py-3 text-center">{{ $l }}</td>
                                        <td class="px-4 py-3 text-center">{{ $p }}</td>
                                        <td class="px-4 py-3 text-center font-bold">{{ $l + $p }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b">
                        <h3 class="font-bold text-gray-800">Data dalam File Profil Excel</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Format ekspor mengikuti workbook yang dijadikan acuan.
                        </p>
                    </div>

                    <div class="divide-y">
                        @foreach($dataAvailability as $section => $available)
                            <div class="px-5 py-3 flex items-center justify-between gap-4">
                                <span class="text-sm font-medium text-gray-700">{{ $section }}</span>
                                @if($available)
                                    <span class="inline-flex rounded-full bg-emerald-50 text-emerald-700 px-2.5 py-1 text-xs font-bold">
                                        Tersedia
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-amber-50 text-amber-700 px-2.5 py-1 text-xs font-bold">
                                        Belum tersedia
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-4 border-b flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                    <div>
                        <h3 class="font-bold text-gray-800">Rombongan Belajar</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Ringkasan rombel reguler pada semester terbaru.
                        </p>
                    </div>
                    <span class="text-sm text-gray-500">{{ $rombelRows->count() }} rombel</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="px-4 py-3 text-left">Rombel</th>
                                <th class="px-4 py-3 text-center">Tingkat</th>
                                <th class="px-4 py-3 text-center">L</th>
                                <th class="px-4 py-3 text-center">P</th>
                                <th class="px-4 py-3 text-center">Total</th>
                                <th class="px-4 py-3 text-left">Wali Kelas</th>
                                <th class="px-4 py-3 text-left">Kurikulum</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($rombelRows as $row)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-gray-900">{{ $row['nama'] }}</td>
                                    <td class="px-4 py-3 text-center">{{ $row['tingkat'] ?: '-' }}</td>
                                    <td class="px-4 py-3 text-center">{{ $row['l'] }}</td>
                                    <td class="px-4 py-3 text-center">{{ $row['p'] }}</td>
                                    <td class="px-4 py-3 text-center font-semibold">{{ $row['total'] }}</td>
                                    <td class="px-4 py-3">{{ $row['wali_kelas'] }}</td>
                                    <td class="px-4 py-3">{{ $row['kurikulum'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                        Belum ada data rombongan belajar.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm text-blue-800">
                File Excel unduhan berisi tujuh sheet:
                <strong>Profil Sekolah, PTK, Peserta Didik, Rombongan Belajar, Prasarana, Sarana, dan Blockgrant.</strong>
                Data contoh dari file acuan tidak dibawa ke sekolah lain; setiap ekspor dibentuk ulang dari database eduXid.
            </div>

        </div>
    </div>
</x-app-layout>
