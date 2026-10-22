<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Data GTK (Guru & Tenaga Kependidikan)
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif

            <!-- Baris Pencarian & Tombol Aksi -->
            <div class="mb-4 flex flex-col sm:flex-row justify-between items-center gap-4">
                <form action="{{ route('guru.index') }}" method="GET" class="flex gap-2 w-full sm:w-auto">
                    <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Cari semua kolom GTK..." class="border-gray-300 rounded-md shadow-sm p-2 text-sm flex-1 sm:w-64">
                    <input type="hidden" name="sort" value="{{ $sort ?? 'nama' }}">
                    <input type="hidden" name="direction" value="{{ $direction ?? 'asc' }}">
                    <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap transition-colors hover:bg-gray-900">Cari</button>
                    @if($search ?? '')
                        <a href="{{ route('guru.index') }}" class="text-red-500 text-sm py-2 px-2 whitespace-nowrap font-bold hover:text-red-700">Reset</a>
                    @endif
                </form>
                
                <div class="flex gap-2 w-full sm:w-auto">
                    <a href="{{ route('guru.export', request()->query()) }}" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 text-sm font-bold whitespace-nowrap flex-1 text-center transition-colors">
                        Download Data
                    </a>
                    @if(auth()->user()->role === 'admin')
                        <a href="{{ route('guru.export', array_merge(request()->query(), ['include_sensitive' => 1])) }}"
                           onclick="return confirm('EKSPOR RAHASIA: File ini memuat NIK/NIP/NUPTK lengkap. Simpan hanya di tempat aman dan jangan bagikan ke publik. Lanjutkan?')"
                           class="bg-amber-700 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap flex-1 sm:flex-none text-center">Ekspor Identitas Lengkap (Admin)</a>
                    @endif
                    @if(auth()->user()->role === 'admin')
                        <button onclick="openModal()" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 text-sm font-bold whitespace-nowrap flex-1 sm:flex-none transition-colors">
                            + Tambah GTK
                        </button>
                    @endif
                </div>
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
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">No</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'nip', 'direction' => $sort == 'nip' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">NIP {!! sortArrow('nip', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'nuptk', 'direction' => $sort == 'nuptk' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">NUPTK {!! sortArrow('nuptk', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'nik', 'direction' => $sort == 'nik' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">NIK {!! sortArrow('nik', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'nama', 'direction' => $sort == 'nama' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">Nama Lengkap {!! sortArrow('nama', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'jenis_kelamin', 'direction' => $sort == 'jenis_kelamin' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">L/P {!! sortArrow('jenis_kelamin', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tempat, Tgl Lahir</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agama</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'jenis_ptk_id_str', 'direction' => $sort == 'jenis_ptk_id_str' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">Jenis GTK {!! sortArrow('jenis_ptk_id_str', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jabatan</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status Kepegawaian</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pendidikan Akhir</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bidang Studi</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a href="{{ route('guru.index', ['sort' => 'pangkat_golongan_terakhir', 'direction' => $sort == 'pangkat_golongan_terakhir' && $direction == 'asc' ? 'desc' : 'asc', 'search' => $search ?? '']) }}" class="hover:text-gray-700">Pangkat/Gol {!! sortArrow('pangkat_golongan_terakhir', $sort, $direction) !!}</a>
                                </th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>

                        <tbody class="bg-white divide-y divide-gray-200">
                            @forelse ($teachers as $t)
                                <tr>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $loop->iteration + ($teachers->currentPage() - 1) * $teachers->perPage() }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ \App\Support\SensitiveDataProtector::maskIdentifier($t->nip ?? '') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ \App\Support\SensitiveDataProtector::maskIdentifier($t->nuptk ?? '') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ \App\Support\SensitiveDataProtector::maskIdentifier($t->nik ?? '') }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $t->gelar_depan ? $t->gelar_depan . ' ' : '' }}{{ $t->nama }}{{ $t->gelar_belakang ? ', ' . $t->gelar_belakang : '' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center text-sm text-gray-500">{{ $t->jenis_kelamin ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $t->tempat_lahir ?? '-' }}, 
                                        @if(!empty($t->tanggal_lahir))
                                            {{ \Carbon\Carbon::parse($t->tanggal_lahir)->format('d/m/Y') }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->agama_id_str ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->jenis_ptk_id_str ?? $t->jenis_ptk ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->jabatan_ptk_id_str ?? $t->jabatan_ptk ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->status_kepegawaian_id_str ?? $t->status_kepegawaian ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->pendidikan_terakhir ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->bidang_studi_terakhir ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $t->pangkat_lengkap ?? '-' }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium">
                                        <button onclick="openDetailModal(this)" 
                                            class="text-gray-600 hover:text-gray-900 font-bold mr-2"
                                            data-nama="{{ $t->nama ?? '' }}"
                                            data-nip="{{ auth()->user()->role === 'admin' ? ($t->nip ?? '') : \App\Support\SensitiveDataProtector::maskIdentifier($t->nip ?? '') }}"
                                            data-nuptk="{{ auth()->user()->role === 'admin' ? ($t->nuptk ?? '') : \App\Support\SensitiveDataProtector::maskIdentifier($t->nuptk ?? '') }}"
                                            data-nik="{{ auth()->user()->role === 'admin' ? ($t->nik ?? '') : \App\Support\SensitiveDataProtector::maskIdentifier($t->nik ?? '') }}"
                                            data-jk="{{ $t->jenis_kelamin ?? '' }}"
                                            data-tempat-lahir="{{ $t->tempat_lahir ?? '' }}"
                                            data-tgl-lahir="{{ $t->tanggal_lahir ?? '' }}"
                                            data-agama="{{ $t->agama_id_str ?? '' }}"
                                            data-jenis-ptk="{{ $t->jenis_ptk_id_str ?? '' }}"
                                            data-jabatan="{{ $t->jabatan_ptk_id_str ?? '' }}"
                                            data-status="{{ $t->status_kepegawaian_id_str ?? '' }}"
                                            data-pendidikan="{{ $t->pendidikan_terakhir ?? '' }}"
                                            data-bidang-studi="{{ $t->bidang_studi_terakhir ?? '' }}"
                                            data-pangkat="{{ $t->pangkat_golongan_terakhir ?? '' }}"
                                            data-ptk-id="{{ $t->dapodik_ptk_id ?? '' }}"
                                            data-ptk-terdaftar-id="{{ $t->ptk_terdaftar_id ?? '' }}"
                                            data-ptk-induk="{{ $t->ptk_induk ?? '' }}"
                                            data-tgl-surat-tugas="{{ $t->tanggal_surat_tugas ?? '' }}"
                                            data-tahun-ajaran="{{ $t->tahun_ajaran_id ?? '' }}"
                                            data-rwy-pend-formal="{{ $t->rwy_pend_formal ?? '[]' }}"
                                            data-rwy-kepangkatan="{{ $t->rwy_kepangkatan ?? '[]' }}"
                                        >Detail</button>

                                        @if(auth()->user()->role === 'admin')
                                            <button onclick="editModal(
                                                '{{ $t->id }}', 
                                                '{{ route('guru.update', $t->id) }}', 
                                                '{{ addslashes($t->nik) }}', 
                                                '{{ addslashes($t->gelar_depan) }}', 
                                                '{{ addslashes($t->nama) }}', 
                                                '{{ addslashes($t->gelar_belakang) }}', 
                                                '{{ $t->jenis_kelamin }}', 
                                                '{{ addslashes($t->nuptk) }}', 
                                                '{{ addslashes($t->nip) }}', 
                                                '{{ addslashes($t->jenis_ptk_id_str ?? $t->jenis_ptk) }}',
                                                '{{ addslashes($t->jabatan_ptk_id_str ?? $t->jabatan_ptk) }}',
                                                '{{ addslashes($t->status_kepegawaian_id_str ?? $t->status_kepegawaian) }}',
                                                '{{ addslashes($t->pangkat_golongan_terakhir) }}'
                                            )" class="text-indigo-600 hover:text-indigo-900 font-bold mr-2">Edit</button>

                                            <form action="{{ route('guru.destroy', $t->id) }}" method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus data ini?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-red-600 hover:text-red-900 font-bold">Hapus</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="16" class="px-4 py-6 text-center text-sm text-gray-500">
                                        Tidak ada data GTK ditemukan.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginasi -->
                <div class="px-4 py-3 bg-white border-t flex flex-col sm:flex-row justify-between items-center gap-3">
                    <div class="text-sm text-gray-600">
                        Menampilkan {{ $teachers->firstItem() ?? 0 }} - {{ $teachers->lastItem() ?? 0 }} dari {{ $teachers->total() ?? 0 }} data
                    </div>
                    <div>
                        {{ $teachers->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Tambah/Edit GTK -->
    @if(auth()->user()->role === 'admin')
    <div id="modal-guru" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden justify-center items-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl p-6 relative my-8 overflow-y-auto max-h-[90vh]">
            <button onclick="closeModal()" class="absolute top-3 right-3 text-gray-400 hover:text-red-500 text-2xl leading-none">&times;</button>
            
            <h3 id="modal-title" class="text-lg font-bold mb-4">Tambah GTK</h3>
            
            <form id="form-guru" method="POST" action="">
                @csrf
                <input type="hidden" id="_method" name="_method" value="POST">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">NIK (Digunakan untuk Login)</label>
                        <input type="text" id="nik" name="nik" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">NIP</label>
                        <input type="text" id="nip" name="nip" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">NUPTK</label>
                        <input type="text" id="nuptk" name="nuptk" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Jenis Kelamin</label>
                        <select id="jenis_kelamin" name="jenis_kelamin" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                            <option value="L">Laki-laki</option>
                            <option value="P">Perempuan</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Gelar Depan</label>
                        <input type="text" id="gelar_depan" name="gelar_depan" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Dr.">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Gelar Belakang</label>
                        <input type="text" id="gelar_belakang" name="gelar_belakang" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="S.Pd., M.Pd.">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap</label>
                        <input type="text" id="nama" name="nama" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Jenis GTK</label>
                        <input type="text" id="jenis_ptk" name="jenis_ptk" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Ex: Guru / Tenaga Kependidikan">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Jabatan</label>
                        <input type="text" id="jabatan_ptk" name="jabatan_ptk" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Ex: Kepala Sekolah / Guru Mapel">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status Kepegawaian</label>
                        <input type="text" id="status_kepegawaian" name="status_kepegawaian" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Ex: PNS / GTT">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Pangkat / Golongan</label>
                        <input type="text" id="pangkat_golongan_terakhir" name="pangkat_golongan_terakhir" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm" placeholder="Ex: III/d">
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 font-bold text-sm transition-colors">Simpan Data GTK</button>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('modal-title').innerText = 'Tambah GTK';
            document.getElementById('form-guru').action = "{{ route('guru.store') }}";
            document.getElementById('_method').value = 'POST';
            document.getElementById('form-guru').reset();
            document.getElementById('jenis_kelamin').value = 'L';
            
            document.getElementById('modal-guru').classList.remove('hidden');
            document.getElementById('modal-guru').classList.add('flex');
        }

        function editModal(id, actionUrl, nik, gelarDepan, nama, gelarBelakang, jk, nuptk, nip, jenisPtk, jabatanPtk, statusKepegawaian, pangkatGol) {
            document.getElementById('modal-title').innerText = 'Edit GTK';
            document.getElementById('form-guru').action = actionUrl;
            document.getElementById('_method').value = 'PUT';
            
            document.getElementById('nik').value = nik || '';
            document.getElementById('nip').value = nip || '';
            document.getElementById('nuptk').value = nuptk || '';
            document.getElementById('jenis_kelamin').value = jk;
            document.getElementById('gelar_depan').value = gelarDepan || '';
            document.getElementById('gelar_belakang').value = gelarBelakang || '';
            document.getElementById('nama').value = nama || '';
            document.getElementById('jenis_ptk').value = jenisPtk || '';
            document.getElementById('jabatan_ptk').value = jabatanPtk || '';
            document.getElementById('status_kepegawaian').value = statusKepegawaian || '';
            document.getElementById('pangkat_golongan_terakhir').value = pangkatGol || '';
            
            document.getElementById('modal-guru').classList.remove('hidden');
            document.getElementById('modal-guru').classList.add('flex');
        }

        function closeModal() {
            document.getElementById('modal-guru').classList.add('hidden');
            document.getElementById('modal-guru').classList.remove('flex');
        }
    </script>
    @endif

    <!-- Modal Detail GTK (Data Dapodik Lengkap) -->
    <div id="modal-detail-guru" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden justify-center items-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-4xl p-6 relative my-8 overflow-y-auto max-h-[90vh]">
            <button onclick="closeDetailModal()" class="absolute top-3 right-3 text-gray-400 hover:text-red-500 text-2xl leading-none">&times;</button>
            
            <h3 class="text-lg font-bold mb-4 border-b pb-2">Detail Data GTK Dapodik</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 text-sm">
                <div><strong>Nama Lengkap:</strong><br><span id="d-nama">-</span></div>
                <div><strong>NIK:</strong><br><span id="d-nik">-</span></div>
                <div><strong>NIP:</strong><br><span id="d-nip">-</span></div>
                <div><strong>NUPTK:</strong><br><span id="d-nuptk">-</span></div>
                <div><strong>Jenis Kelamin:</strong><br><span id="d-jk">-</span></div>
                <div><strong>Tempat, Tgl Lahir:</strong><br><span id="d-lahir">-</span></div>
                <div><strong>Agama:</strong><br><span id="d-agama">-</span></div>
                <div><strong>Jenis PTK:</strong><br><span id="d-jenis-ptk">-</span></div>
                <div><strong>Jabatan PTK:</strong><br><span id="d-jabatan">-</span></div>
                <div><strong>Status Kepegawaian:</strong><br><span id="d-status">-</span></div>
                <div><strong>Pendidikan Terakhir:</strong><br><span id="d-pendidikan">-</span></div>
                <div><strong>Bidang Studi:</strong><br><span id="d-bidang-studi">-</span></div>
                <div><strong>Pangkat Golongan:</strong><br><span id="d-pangkat">-</span></div>
                <div><strong>Tahun Ajaran ID:</strong><br><span id="d-tahun-ajaran">-</span></div>
                <div><strong>PTK Induk:</strong><br><span id="d-ptk-induk">-</span></div>
                <div><strong>Tanggal Surat Tugas:</strong><br><span id="d-tgl-surat">-</span></div>
                <div class="md:col-span-2"><strong>PTK ID:</strong><br><span id="d-ptk-id" class="break-all">-</span></div>
                <div class="md:col-span-3"><strong>PTK Terdaftar ID:</strong><br><span id="d-ptk-terdaftar" class="break-all">-</span></div>
            </div>

            <h4 class="font-bold mb-2 bg-gray-100 p-2 rounded">Riwayat Pendidikan Formal</h4>
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full text-xs border">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-2 py-1 border">Jenjang</th>
                            <th class="px-2 py-1 border">Satuan Pendidikan</th>
                            <th class="px-2 py-1 border">Bidang Studi</th>
                            <th class="px-2 py-1 border">Tahun Lulus</th>
                            <th class="px-2 py-1 border">Gelar Akademik</th>
                        </tr>
                    </thead>
                    <tbody id="d-rwy-pend-formal" class="bg-white">
                        <!-- Diisi via JS -->
                    </tbody>
                </table>
            </div>

            <h4 class="font-bold mb-2 bg-gray-100 p-2 rounded">Riwayat Kepangkatan</h4>
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full text-xs border">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-2 py-1 border">Pangkat Golongan</th>
                            <th class="px-2 py-1 border">TMT Pangkat</th>
                            <th class="px-2 py-1 border">No SK</th>
                            <th class="px-2 py-1 border">Tanggal SK</th>
                            <th class="px-2 py-1 border">Masa Kerja</th>
                        </tr>
                    </thead>
                    <tbody id="d-rwy-kepangkatan" class="bg-white">
                        <!-- Diisi via JS -->
                    </tbody>
                </table>
            </div>

            <button onclick="closeDetailModal()" class="mt-4 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm font-medium w-full">Tutup</button>
        </div>
    </div>

    <script>
        function openDetailModal(el) {
            document.getElementById('d-nama').innerText = el.dataset.nama || '-';
            document.getElementById('d-nik').innerText = el.dataset.nik || '-';
            document.getElementById('d-nip').innerText = el.dataset.nip || '-';
            document.getElementById('d-nuptk').innerText = el.dataset.nuptk || '-';
            document.getElementById('d-jk').innerText = el.dataset.jk || '-';
            
            let tglLahir = el.dataset.tglLahir ? new Date(el.dataset.tglLahir).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
            document.getElementById('d-lahir').innerText = (el.dataset.tempatLahir || '-') + ', ' + tglLahir;
            
            document.getElementById('d-agama').innerText = el.dataset.agama || '-';
            document.getElementById('d-jenis-ptk').innerText = el.dataset.jenisPtk || '-';
            document.getElementById('d-jabatan').innerText = el.dataset.jabatan || '-';
            document.getElementById('d-status').innerText = el.dataset.status || '-';
            document.getElementById('d-pendidikan').innerText = el.dataset.pendidikan || '-';
            document.getElementById('d-bidang-studi').innerText = el.dataset.bidangStudi || '-';
            document.getElementById('d-pangkat').innerText = el.dataset.pangkat || '-';
            document.getElementById('d-tahun-ajaran').innerText = el.dataset.tahunAjaran || '-';
            document.getElementById('d-ptk-induk').innerText = el.dataset.ptkInduk || '-';
            
            let tglSurat = el.dataset.tglSuratTugas ? new Date(el.dataset.tglSuratTugas).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
            document.getElementById('d-tgl-surat').innerText = tglSurat;
            
            document.getElementById('d-ptk-id').innerText = el.dataset.ptkId || '-';
            document.getElementById('d-ptk-terdaftar').innerText = el.dataset.ptkTerdaftarId || '-';

            // Parse Riwayat Pendidikan (Pastikan di-parse jika ternyata masih string JSON)
            let pendFormal = [];
            try { 
                pendFormal = JSON.parse(el.dataset.rwyPendFormal || '[]');
                if (typeof pendFormal === 'string') {
                    pendFormal = JSON.parse(pendFormal);
                }
            } catch(e) {
                pendFormal = [];
            }
            
            let pfBody = document.getElementById('d-rwy-pend-formal');
            pfBody.innerHTML = '';
            
            if (!Array.isArray(pendFormal) || pendFormal.length === 0) {
                pfBody.innerHTML = '<tr><td colspan="5" class="text-center px-2 py-2 border">Tidak ada riwayat pendidikan</td></tr>';
            } else {
                pendFormal.forEach(item => {
                    pfBody.innerHTML += `<tr>
                        <td class="px-2 py-1 border">${item.jenjang_pendidikan_id_str || '-'}</td>
                        <td class="px-2 py-1 border">${item.satuan_pendidikan_formal || '-'}</td>
                        <td class="px-2 py-1 border">${item.bidang_studi_id_str || '-'}</td>
                        <td class="px-2 py-1 border">${item.tahun_lulus || '-'}</td>
                        <td class="px-2 py-1 border">${item.gelar_akademik_id_str || '-'}</td>
                    </tr>`;
                });
            }

            // Parse Riwayat Kepangkatan
            let kepangkatan = [];
            try { 
                kepangkatan = JSON.parse(el.dataset.rwyKepangkatan || '[]');
                if (typeof kepangkatan === 'string') {
                    kepangkatan = JSON.parse(kepangkatan);
                }
            } catch(e) {
                kepangkatan = [];
            }
            
            let kpBody = document.getElementById('d-rwy-kepangkatan');
            kpBody.innerHTML = '';
            
            if (!Array.isArray(kepangkatan) || kepangkatan.length === 0) {
                kpBody.innerHTML = '<tr><td colspan="5" class="text-center px-2 py-2 border">Tidak ada riwayat kepangkatan</td></tr>';
            } else {
                kepangkatan.forEach(item => {
                    let tmt = item.tmt_pangkat ? new Date(item.tmt_pangkat).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
                    let tglSk = item.tanggal_sk ? new Date(item.tanggal_sk).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
                    let masaKerja = (item.masa_kerja_gol_tahun || '0') + ' Thn ' + (item.masa_kerja_gol_bulan || '0') + ' Bln';
                    kpBody.innerHTML += `<tr>
                        <td class="px-2 py-1 border">${item.pangkat_golongan_id_str || '-'}</td>
                        <td class="px-2 py-1 border">${tmt}</td>
                        <td class="px-2 py-1 border">${item.nomor_sk || '-'}</td>
                        <td class="px-2 py-1 border">${tglSk}</td>
                        <td class="px-2 py-1 border">${masaKerja}</td>
                    </tr>`;
                });
            }

            document.getElementById('modal-detail-guru').classList.remove('hidden');
            document.getElementById('modal-detail-guru').classList.add('flex');
        }

        function closeDetailModal() {
            document.getElementById('modal-detail-guru').classList.add('hidden');
            document.getElementById('modal-detail-guru').classList.remove('flex');
        }
    </script>
</x-app-layout>