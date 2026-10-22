<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pengaturan Kokurikuler
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Form Tambah -->
                <div class="bg-white p-6 rounded-lg shadow-sm lg:col-span-1">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Tambah Kegiatan</h3>
                    <form action="{{ route('admin.kokurikuler.store') }}" method="POST">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tema</label>
                            <input type="text" name="tema" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Judul Kegiatan</label>
                            <input type="text" name="nama_kegiatan" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Kelas</label>
                            <select name="homeroom_id" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                                <option value="">-- Pilih Kelas --</option>
                                @foreach ($rombels as $r)
                                    <option value="{{ $r->id }}">{{ $r->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Koordinator</label>
                            <select name="koordinator_id" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                                <option value="">-- Pilih Guru --</option>
                                @foreach ($gurus as $g)
                                    @php
                                        // Gunakan nama_lengkap dari DB, jika tidak ada rangkai manual dengan Title Case
                                        $namaTampil = !empty($g->nama_lengkap) 
                                            ? $g->nama_lengkap 
                                            : trim(($g->gelar_depan ? $g->gelar_depan . ' ' : '') . mb_convert_case($g->nama ?? '', MB_CASE_TITLE, 'UTF-8') . ($g->gelar_belakang ? ', ' . $g->gelar_belakang : ''));
                                    @endphp
                                    <option value="{{ $g->id }}">{{ $namaTampil }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 text-sm font-bold">+ Tambah & Simpan</button>
                    </form>
                </div>

                <!-- Tabel Daftar -->
                <div class="bg-white p-6 rounded-lg shadow-sm lg:col-span-2">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Daftar Kegiatan Kokurikuler</h3>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tema</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Kegiatan</th>
                                <!-- Kolom Kelas Ditambahkan -->
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kelas</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Koordinator</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($kokurikulers as $k)
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900">{{ $k->tema }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $k->nama_kegiatan }}</td>
                                    <!-- Kolom Kelas Ditambahkan -->
                                    <td class="px-4 py-3 text-sm text-gray-500">{{ $k->kelas ?? '-' }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        @php
                                            // Untuk tabel, kita gunakan mb_convert_case jika data dari DB belum dirangkai nama_lengkapnya
                                            // Jika controller mengirim $k->koordinator_lengkap, gunakan itu. Jika hanya nama, format saja.
                                            $koordinatorNama = $k->koordinator_lengkap ?? $k->koordinator;
                                            echo mb_convert_case($koordinatorNama ?? '-', MB_CASE_TITLE, 'UTF-8');
                                        @endphp
                                    </td>
                                    <td class="px-4 py-3 text-center text-sm font-medium">
                                        <form action="{{ route('admin.kokurikuler.destroy', $k->id) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('Yakin hapus?')">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            @if($kokurikulers->isEmpty())
                                <tr><td colspan="5" class="px-4 py-3 text-center text-sm text-gray-500">Belum ada kegiatan.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>