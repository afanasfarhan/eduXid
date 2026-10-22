<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Bimbingan Konseling (BK)
        </h2>
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

            @if (session('warning'))
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-6">
                    {{ session('warning') }}
                </div>
            @endif

            {{-- [ERROR-FIX] Dulu tidak ada: kegagalan validasi store()
                 menampilkan ulang form TANPA penjelasan. --}}
            @if ($errors->any())
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <p class="font-bold mb-1">Terjadi kesalahan:</p>
                    <ul class="list-disc list-inside text-sm space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Form Input Kasus BK -->
            <div class="bg-white p-6 rounded-lg shadow-sm mb-8">
                <h3 class="text-lg font-bold text-gray-800 mb-4">Input Kasus Baru</h3>

                <form action="{{ route('guru.bk.store') }}" method="POST" id="form-bk">
                    @csrf

                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                            <input
                                type="date"
                                name="tanggal"
                                value="{{ old('tanggal', date('Y-m-d')) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                required
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nama Murid</label>
                            <select
                                name="student_id"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                required
                            >
                                <option value="">-- Pilih Murid --</option>

                                @foreach ($students as $s)
                                    <option value="{{ $s->id }}" @selected(old('student_id') == $s->id)>
                                        {{ $s->nama }} ({{ $s->nisn }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bobot Kasus</label>
                            <select
                                name="bobot_kasus"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                required
                            >
                                <option value="Ringan" @selected(old('bobot_kasus') === 'Ringan')>Ringan</option>
                                <option value="Sedang" @selected(old('bobot_kasus') === 'Sedang')>Sedang</option>
                                <option value="Berat" @selected(old('bobot_kasus') === 'Berat')>Berat</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Poin</label>
                            <input
                                type="number"
                                name="poin"
                                min="0"
                                value="{{ old('poin', 0) }}"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                required
                            >
                        </div>
                    </div>

                    <!-- Form Permasalahan & Penanganan -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Permasalahan</label>
                            <textarea
                                name="permasalahan"
                                rows="3"
                                maxlength="2000"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                placeholder="Deskripsi permasalahan murid..."
                                required
                            >{{ old('permasalahan') }}</textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Penanganan</label>
                            <textarea
                                name="penanganan"
                                rows="3"
                                maxlength="2000"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                placeholder="Tindakan/penanganan yang dilakukan..."
                                required
                            >{{ old('penanganan') }}</textarea>
                        </div>
                    </div>

                    <div class="text-right">
                        <button
                            type="submit"
                            id="btn-submit-bk"
                            class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm"
                        >
                            💾 Simpan Riwayat
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tabel Riwayat BK -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">No</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">NISN</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Murid</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kelas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Permasalahan</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Penanganan</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Bobot</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Poin</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Total Poin</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>

                        @php
                            /*
                             * [PERF-FIX] Total poin per siswa dihitung SEKALI di luar
                             * loop — dulu $riwayats->where(...)->sum(...) dieksekusi
                             * per baris (O(n²)): 100 kasus = 10.000 iterasi.
                             * Kini: groupBy NISN -> map {nisn => total} -> O(n).
                             */
                            $totalPoinByNisn = $riwayats
                                ->groupBy('nisn')
                                ->map(fn ($group) => (int) $group->sum('poin'));
                        @endphp

                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse ($riwayats as $r)
                                @php
                                    /*
                                     * Total seluruh poin siswa (kumulatif per NISN) —
                                     * diambil dari map yang sudah dihitung sekali.
                                     */
                                    $totalPoinSiswa = $totalPoinByNisn[$r->nisn] ?? 0;
                                @endphp

                                <tr>
                                    <td class="px-4 py-3 text-center text-sm text-gray-500">
                                        {{ $loop->iteration }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {{ date('d/m/Y', strtotime($r->tanggal)) }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $r->nisn }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $r->nama }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $r->kelas ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        {{ $r->permasalahan }}
                                    </td>

                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        {{ $r->penanganan }}
                                    </td>

                                    <td class="px-4 py-3 text-center text-sm">
                                        @if($r->bobot_kasus === 'Berat')
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                {{ $r->bobot_kasus }}
                                            </span>
                                        @elseif($r->bobot_kasus === 'Sedang')
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                {{ $r->bobot_kasus }}
                                            </span>
                                        @else
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                {{ $r->bobot_kasus }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 text-center text-sm font-bold text-gray-900">
                                        {{ $r->poin }}
                                    </td>

                                    <!-- Total seluruh poin siswa -->
                                    <td class="px-4 py-3 text-center text-sm font-bold text-indigo-700">
                                        {{ $totalPoinSiswa }}
                                    </td>

                                    <!-- Tombol Hapus -->
                                    <td class="px-4 py-3 text-center">
                                        <form
                                            action="{{ route('guru.bk.destroy', $r->id) }}"
                                            method="POST"
                                            onsubmit="return confirm('Apakah Anda yakin ingin menghapus riwayat kasus BK ini?');"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            {{-- [CLASS-FIX] "text-red" bukan class Tailwind yang valid —
                                                 tombol dulu tampak polos tanpa warna. --}}
                                            <button
                                                type="submit"
                                                class="inline-flex items-center text-red-600 hover:text-red-900 font-bold px-3 py-1.5 rounded-md text-xs whitespace-nowrap"
                                            >
                                                Hapus
                                            </button>
                                        </form>
                                    </td>

                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="px-6 py-4 text-center text-sm text-gray-500">
                                        Belum ada riwayat kasus BK.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Tombol Download Rekap -->
                <div class="p-4 border-t text-right">
                    <a
                        href="{{ route('guru.bk.export') }}"
                        class="inline-flex items-center bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 font-bold text-sm shadow-sm whitespace-nowrap"
                    >
                        📊 Download Rekap Kasus (Excel)
                    </a>
                </div>
            </div>

        </div>
    </div>
</x-app-layout>