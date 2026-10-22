<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Manajemen Kelas & Rombongan Belajar
        </h2>
    </x-slot>

    <!-- x-data dipindah ke container utama agar scope-nya benar -->
    <div class="py-12" x-data="{ showForm: false }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    {{ session('success') }}
                </div>
            @endif

            <!-- Baris Pencarian & Tombol Tambah -->
            <div class="mb-4 flex flex-col sm:flex-row justify-between items-center gap-4">
                
                <!-- Form Pencarian -->
                <form method="GET" action="{{ route('admin.kelas') }}" class="flex gap-2 w-full sm:w-auto">
                    <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Cari nama kelas / wali..." class="border-gray-300 rounded-md shadow-sm p-2 text-sm flex-1 sm:w-64">
                    <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap transition-colors hover:bg-gray-900">Cari</button>
                    @if($search ?? '')
                        <a href="{{ route('admin.kelas') }}" class="text-red-500 text-sm py-2 px-2 whitespace-nowrap font-bold hover:text-red-700">Reset</a>
                    @endif
                </form>

                <!-- Tombol Tambah Kelas -->
                <button @click="showForm = true" class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap w-full sm:w-auto transition-colors hover:bg-indigo-700">
                    + Tambah Kelas
                </button>
            </div>

            <!-- Kartu Tabel -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.kelas', ['sort' => 'nama', 'direction' => $sort == 'nama' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Nama Kelas @if($sort == 'nama') <span class="ml-1">{{ $direction == 'asc' ? '▲' : '▼' }}</span> @endif
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.kelas', ['sort' => 'tingkat_pendidikan', 'direction' => $sort == 'tingkat_pendidikan' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Tingkat @if($sort == 'tingkat_pendidikan') <span class="ml-1">{{ $direction == 'asc' ? '▲' : '▼' }}</span> @endif
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.kelas', ['sort' => 'jenis_rombel', 'direction' => $sort == 'jenis_rombel' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Jenis Rombel @if($sort == 'jenis_rombel') <span class="ml-1">{{ $direction == 'asc' ? '▲' : '▼' }}</span> @endif
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.kelas', ['sort' => 'wali_kelas', 'direction' => $sort == 'wali_kelas' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Wali Kelas / Pembina @if($sort == 'wali_kelas') <span class="ml-1">{{ $direction == 'asc' ? '▲' : '▼' }}</span> @endif
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse ($kelas as $k)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $k->nama }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $k->tingkat_pendidikan ?? '-' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            @if(str_contains($k->jenis_rombel ?? '', 'Ekstrakurikuler')) bg-green-100 text-green-800 
                                            @elseif(str_contains($k->jenis_rombel ?? '', 'Pilihan')) bg-yellow-100 text-yellow-800 
                                            @else bg-blue-100 text-blue-800 @endif">
                                            {{ $k->jenis_rombel ?? 'Kelas Reguler' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <form action="{{ route('admin.kelas.updateWali', $k->id) }}" method="POST" class="flex items-center gap-2">
                                            @csrf
                                            @method('PUT')
                                            <select name="homeroom_teacher_id" class="border-gray-300 rounded-md shadow-sm text-sm p-1 w-48">
                                                <option value="">- Wali/Pembina -</option>
                                                @foreach($gurus as $g)
                                                    @php
                                                        // Gunakan nama_lengkap dari DB, jika tidak ada rangkai manual dengan Title Case
                                                        $namaTampil = !empty($g->nama_lengkap) 
                                                            ? $g->nama_lengkap 
                                                            : trim(($g->gelar_depan ? $g->gelar_depan . ' ' : '') . mb_convert_case($g->nama ?? '', MB_CASE_TITLE, 'UTF-8') . ($g->gelar_belakang ? ', ' . $g->gelar_belakang : ''));
                                                    @endphp
                                                    <option value="{{ $g->id }}" @selected($k->wali_id == $g->id)>{{ $namaTampil }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-200 text-xs font-bold px-2 py-1 rounded transition-colors whitespace-nowrap">Update</button>
                                        </form>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium">
                                        <a href="{{ route('admin.kelas.anggota', $k->id) }}" class="text-indigo-600 hover:text-indigo-900 font-bold mr-3">Lihat Anggota</a>
                                        <form action="{{ route('admin.kelas.destroy', $k->id) }}" method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus kelas ini? Seluruh data anggota kelas juga akan dihapus.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-bold">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">
                                        Tidak ada data kelas ditemukan.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginasi -->
                <div class="px-4 py-3 bg-white border-t flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div class="text-sm text-gray-600">
                        Menampilkan {{ $kelas->firstItem() ?? 0 }} - {{ $kelas->lastItem() ?? 0 }} dari {{ $kelas->total() ?? 0 }} data
                    </div>
                    <div>
                        {{ $kelas->links() }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Form Tambah Kelas -->
        <div x-show="showForm" style="display: none;" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
            <div class="bg-white p-6 rounded-lg shadow-xl w-full max-w-md mx-4" @click.away="showForm = false">
                <h3 class="text-lg font-bold mb-4">Tambah Kelas / Rombel Baru</h3>
                <form action="{{ route('admin.kelas.store') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700">Nama Kelas *</label>
                        <input type="text" name="nama" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Contoh: XII IPA 1 / Futsal">
                    </div>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tingkat</label>
                            <input type="text" name="tingkat_pendidikan" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Contoh: Kelas 12">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Semester ID</label>
                            <input type="text" name="semester_id" value="{{ date('Y') }}1" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Contoh: 20241">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700">Jenis Rombel *</label>
                        <select name="jenis_rombel" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                            <option value="Kelas Reguler">Kelas Reguler</option>
                            <option value="Ekstrakurikuler">Ekstrakurikuler</option>
                            <option value="Matapelajaran Pilihan">Mata Pelajaran Pilihan</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700">Wali Kelas / Pembina</label>
                        <select name="homeroom_teacher_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                            <option value="">- Pilih Guru -</option>
                            @foreach($gurus as $g)
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
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">Kurikulum</label>
                        <input type="text" name="kurikulum" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Contoh: Kurikulum Merdeka">
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showForm = false" class="bg-gray-200 text-gray-800 px-4 py-2 rounded-md text-sm font-bold hover:bg-gray-300 transition-colors">Batal</button>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-bold hover:bg-indigo-700 transition-colors">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>