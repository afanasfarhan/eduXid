<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Manajemen Pembelajaran
        </h2>
    </x-slot>

    <!-- x-data untuk kontrol Modal -->
    <div class="py-12" x-data="{ showForm: false }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif

            <!-- Baris Pencarian & Tombol Tambah -->
            <div class="mb-4 flex flex-col sm:flex-row justify-between items-center gap-4">
                <form method="GET" action="{{ route('admin.pembelajaran') }}" class="flex gap-2 w-full sm:w-auto">
                    <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Cari kelas, mapel, guru..." class="border-gray-300 rounded-md shadow-sm p-2 text-sm flex-1 sm:w-64" autocomplete="off">
                    <input type="hidden" name="sort" value="{{ $sort ?? 'kelas' }}">
                    <input type="hidden" name="direction" value="{{ $direction ?? 'asc' }}">
                    <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap transition-colors hover:bg-gray-900">Cari</button>
                    @if(isset($search) && $search)
                        <a href="{{ route('admin.pembelajaran') }}" class="text-red-500 text-sm py-2 px-2 whitespace-nowrap font-bold hover:text-red-700">Reset</a>
                    @endif
                </form>

                <button @click="showForm = true" class="bg-indigo-600 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap w-full sm:w-auto transition-colors hover:bg-indigo-700">
                    + Tambah Pembelajaran
                </button>
            </div>

            <!-- Kartu Tabel -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-100">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        @php
                            function sortArrow($col, $currentSort, $currentDir) {
                                if($col == $currentSort) return $currentDir == 'asc' ? ' ▲' : ' ▼';
                                return '';
                            }
                        @endphp

                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.pembelajaran', ['sort' => 'kelas', 'direction' => $sort == 'kelas' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Kelas {!! sortArrow('kelas', $sort, $direction) !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.pembelajaran', ['sort' => 'mapel', 'direction' => $sort == 'mapel' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Mata Pelajaran {!! sortArrow('mapel', $sort, $direction) !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('admin.pembelajaran', ['sort' => 'guru', 'direction' => $sort == 'guru' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="flex items-center hover:text-gray-700">
                                        Guru Pengajar {!! sortArrow('guru', $sort, $direction) !!}
                                    </a>
                                </th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>

                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse ($pembelajaran as $p)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ $p->kelas }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $p->mapel }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <form action="{{ route('admin.pembelajaran.update', $p->id) }}" method="POST" class="flex items-center gap-2">
                                            @csrf
                                            @method('PUT')
                                            <select name="teacher_id" class="border-gray-300 rounded-md shadow-sm text-sm w-full p-1">
                                                @foreach ($gurus as $g)
                                                    <option value="{{ $g->id }}" @if($p->guru_id == $g->id) selected @endif>
                                                        {{ $g->gelar_depan ? $g->gelar_depan . ' ' : '' }}{{ mb_convert_case(strtolower($g->nama), MB_CASE_TITLE, 'UTF-8') }}{{ $g->gelar_belakang ? ', ' . $g->gelar_belakang : '' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="bg-indigo-100 text-indigo-700 hover:bg-indigo-200 text-xs font-bold px-2 py-1 rounded transition-colors whitespace-nowrap">Update</button>
                                        </form>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium">
                                        <form action="{{ route('admin.pembelajaran.destroy', $p->id) }}" method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus data pembelajaran ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-bold">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                                        Tidak ada data pembelajaran ditemukan.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginasi -->
                <div class="px-4 py-3 bg-white border-t flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div class="text-sm text-gray-600">
                        Menampilkan {{ $pembelajaran->firstItem() ?? 0 }} - {{ $pembelajaran->lastItem() ?? 0 }} dari {{ $pembelajaran->total() ?? 0 }} data
                    </div>
                    <div>
                        {{ $pembelajaran->links() }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Form Tambah Pembelajaran -->
        <div x-show="showForm" style="display: none;" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center">
            <div class="bg-white p-6 rounded-lg shadow-xl w-full max-w-md mx-4" @click.away="showForm = false">
                <h3 class="text-lg font-bold mb-4">Tambah Pembelajaran Baru</h3>
                <form action="{{ route('admin.pembelajaran.store') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700">Kelas / Rombel *</label>
                        <select name="homeroom_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                            <option value="">- Pilih Kelas -</option>
                            @foreach($rombels as $r)
                                <option value="{{ $r->id }}">{{ $r->nama }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium text-gray-700">Mata Pelajaran *</label>
                        <select name="subject_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                            <option value="">- Pilih Mapel -</option>
                            @foreach($mapels as $m)
                                <option value="{{ $m->id }}">{{ $m->nama_mata_pelajaran }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700">Guru Pengajar *</label>
                        <select name="teacher_id" required class="mt-1 block w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                            <option value="">- Pilih Guru -</option>
                            @foreach($gurus as $g)
                                <option value="{{ $g->id }}">{{ $g->gelar_depan ? $g->gelar_depan . ' ' : '' }}{{ $g->nama }}{{ $g->gelar_belakang ? ', ' . $g->gelar_belakang : '' }}</option>
                            @endforeach
                        </select>
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