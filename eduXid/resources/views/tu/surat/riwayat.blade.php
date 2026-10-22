<x-app-layout>

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Arsip Surat Dinas
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                    {{ session('success') }}
                </div>
            @endif

            {{-- ==========================================================
                 KONTEN SURAT KELUAR
            =========================================================== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="font-semibold text-lg text-gray-800">Daftar Surat Keluar</h3>
                    <a href="{{ route('tu.surat.index') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        + Buat Surat Baru
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 table-fixed">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 w-12">No</th>
                                <th class="px-6 py-3">Tanggal Surat</th>
                                <th class="px-6 py-3">Nomor Surat</th>
                                <th class="px-6 py-3">Jenis Surat</th>
                                <th class="px-6 py-3">Penerima</th>
                                <th class="px-6 py-3">Perihal</th>
                                <th class="px-6 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($riwayats as $index => $riwayat)
                                @php
                                    // Ambil data penerima dari JSON data_surat
                                    $dataSurat = is_array($riwayat->data_surat)
                                        ? $riwayat->data_surat
                                        : json_decode((string) $riwayat->data_surat, true);

                                    if (!is_array($dataSurat)) {
                                        $dataSurat = [];
                                    }

                                    $penerima = $dataSurat['tujuan'] ?? $dataSurat['nama_siswa'] ?? '-';
                                @endphp
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4">{{ $riwayats->firstItem() + $index }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if (!empty($riwayat->tanggal_surat))
                                            {{ \Carbon\Carbon::parse($riwayat->tanggal_surat)->format('d/m/Y') }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 truncate">{{ $riwayat->nomor_surat }}</td>
                                    <td class="px-6 py-4 font-medium text-gray-900 truncate">{{ $riwayat->jenis_surat }}</td>
                                    <td class="px-6 py-4 truncate">{{ $penerima }}</td>
                                    <td class="px-6 py-4 truncate">{{ $riwayat->perihal }}</td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        <a href="{{ route('tu.surat.cetakUlang', $riwayat->id) }}" target="_blank" class="text-blue-600 hover:text-blue-900 font-medium mr-3">Cetak Ulang</a>
                                        <form action="{{ route('tu.surat.destroy', $riwayat->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Yakin ingin menghapus arsip surat ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-medium">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-400">Belum ada riwayat surat keluar yang dibuat.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t">
                    {{ $riwayats->links() }}
                </div>
            </div>

            {{-- ==========================================================
                 KONTEN SURAT MASUK
            =========================================================== --}}
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="p-6 border-b flex justify-between items-center">
                    <h3 class="font-semibold text-lg text-gray-800">Daftar Surat Masuk</h3>
                    <button onclick="openModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        + Input Surat Masuk
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-600 table-fixed">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 w-12">No</th>
                                <th class="px-6 py-3">Tanggal Surat</th>
                                <th class="px-6 py-3">Nomor Surat</th>
                                <th class="px-6 py-3">Jenis Surat</th>
                                <th class="px-6 py-3">Pengirim</th>
                                <th class="px-6 py-3">Perihal</th>
                                <th class="px-6 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($suratMasuk as $index => $surat)
                                <tr class="bg-white border-b hover:bg-gray-50">
                                    <td class="px-6 py-4">{{ $suratMasuk->firstItem() + $index }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if (!empty($surat->tanggal_surat))
                                            {{ \Carbon\Carbon::parse($surat->tanggal_surat)->format('d/m/Y') }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 truncate">{{ $surat->nomor_surat }}</td>
                                    <td class="px-6 py-4 font-medium text-gray-900 truncate">{{ $surat->jenis_surat }}</td>
                                    <td class="px-6 py-4 truncate">{{ $surat->pengirim ?? '-' }}</td>
                                    <td class="px-6 py-4 truncate">{{ $surat->perihal }}</td>
                                    <td class="px-6 py-4 text-center whitespace-nowrap">
                                        @if (!empty($surat->file_path))
                                            <a href="{{ app(\App\Services\LocalMediaService::class)->url($surat->file_path) }}" target="_blank" class="text-blue-600 hover:text-blue-900 font-medium mr-3">Lihat PDF</a>
                                        @else
                                            <span class="text-gray-400 mr-3">Tanpa PDF</span>
                                        @endif
                                        <form action="{{ route('tu.surat.destroyMasuk', $surat->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Yakin ingin menghapus surat masuk ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900 font-medium">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center text-gray-400">Belum ada surat masuk yang diarsipkan.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t">
                    {{ $suratMasuk->links() }}
                </div>
            </div>

        </div>
    </div>

    {{-- ==========================================================
         MODAL INPUT SURAT MASUK
    ========================================================== --}}
    <div id="modalSuratMasuk" class="fixed inset-0 z-50 hidden bg-gray-900 bg-opacity-50 flex items-center justify-center p-4" onclick="if(event.target === this) closeModal()">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h4 class="text-lg font-semibold text-gray-800">Input Surat Masuk</h4>
                <button type="button" onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            
            <form action="{{ route('tu.surat.storeMasuk') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Jenis Surat</label>
                        <input type="text" name="jenis_surat" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Contoh: Surat Pemberitahuan" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Nomor Surat</label>
                        <input type="text" name="nomor_surat" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tanggal Surat</label>
                        <input type="date" name="tanggal_surat" value="{{ date('Y-m-d') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Pengirim</label>
                        <input type="text" name="pengirim" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Contoh: Dinas Pendidikan / SMP Negeri 1">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Perihal</label>
                        <input type="text" name="perihal" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Upload File PDF (Opsional)</label>
                        <input type="file" name="file_surat" accept="application/pdf" class="mt-1 block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm font-medium">Batal</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">Simpan Surat Masuk</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('modalSuratMasuk').classList.remove('hidden');
        }
        function closeModal() {
            document.getElementById('modalSuratMasuk').classList.add('hidden');
        }
    </script>

</x-app-layout>