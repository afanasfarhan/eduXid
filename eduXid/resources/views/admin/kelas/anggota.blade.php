<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Anggota Kelas: {{ $rombel->nama }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- Form Tambah Siswa -->
                <div class="bg-white p-6 rounded-lg shadow-sm lg:col-span-1">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Tambah Murid</h3>
                    <form action="{{ route('admin.kelas.anggotaStore', $rombel->id) }}" method="POST">
                        @csrf
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Pilih Murid</label>
                            <select name="student_id" class="w-full border-gray-300 rounded-md shadow-sm text-sm" required>
                                <option value="">-- Pilih Murid --</option>
                                @foreach ($siswaTersedia as $s)
                                    <option value="{{ $s->id }}">{{ mb_convert_case(strtolower($s->nama), MB_CASE_TITLE, 'UTF-8') }} ({{ $s->nisn }})</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 text-sm font-bold">
                            + Tambah ke Kelas
                        </button>
                    </form>
                </div>

                <!-- Tabel Anggota -->
                <div class="bg-white p-6 rounded-lg shadow-sm lg:col-span-2">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Daftar Anggota ({{ $anggota->count() }} Murid)</h3>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">NISN</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Murid</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach ($anggota as $a)
                                <tr data-eduxid-student-nisn="{{ $a->nisn }}" data-eduxid-student-name="{{ $a->nama }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ $a->nisn }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ mb_convert_case(strtolower($a->nama), MB_CASE_TITLE, 'UTF-8') }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                        <form action="{{ route('admin.kelas.anggotaDestroy', $a->enrollment_id) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('Yakin mengeluarkan murid ini?')">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            @if($anggota->isEmpty())
                                <tr>
                                    <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">Belum ada anggota di kelas ini.</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</x-app-layout>