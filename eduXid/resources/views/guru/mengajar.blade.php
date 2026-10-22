<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Buku Nilai
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif

            @if(count($mengajars) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @foreach ($mengajars as $m)
                        <!-- Kartu Kelas -->
                        <div class="bg-white rounded-xl shadow-md overflow-hidden flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                            
                            <!-- Header Kartu -->
                            <div class="bg-indigo-50 p-4 border-b border-indigo-100 flex justify-between items-center">
                                <div>
                                    <h3 class="text-lg font-bold text-indigo-900 leading-tight">{{ $m->mapel }}</h3>
                                </div>
                                <span class="bg-white text-indigo-600 border border-indigo-200 px-3 py-1 rounded-full text-sm font-bold shadow-sm">
                                    {{ $m->kelas }}
                                </span>
                            </div>

                            <!-- Body Kartu (Tombol Aksi) -->
                            <div class="p-4 flex-grow flex flex-col justify-center gap-3">
                                <a href="{{ route('rombel.show', $m->homeroom_id) }}?assignment={{ $m->assignment_id }}" class="flex items-center justify-center gap-2 w-full bg-purple-50 text-purple-700 px-4 py-3 rounded-lg hover:bg-purple-100 transition-colors text-sm border border-purple-100">
                                    <span class="text-lg"></span> Denah Kelas
                                </a>

                                <a href="{{ route('guru.nilai', $m->assignment_id) }}" class="flex items-center justify-center gap-2 w-full bg-purple-50 text-purple-700 px-4 py-3 rounded-lg hover:bg-purple-100 transition-colors text-sm border border-purple-100">
                                    <span class="text-lg"></span> Input Nilai Harian
                                </a>

                                <a href="{{ route('guru.nilaiAkhir', $m->assignment_id) }}" class="flex items-center justify-center gap-2 w-full bg-amber-50 text-amber-700 px-4 py-3 rounded-lg hover:bg-amber-100 transition-colors text-sm border border-amber-100">
                                    <span class="text-lg"></span> Input Nilai Akhir & Deskripsi
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <!-- Tampilan Kosong (Empty State) -->
                <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                    <div class="text-6xl mb-4">📭</div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">Belum Ada Jadwal Mengajar</h3>
                    <p class="text-gray-500">Saat ini tidak ada kelas atau mata pelajaran yang terdaftar pada akun Anda. Silakan hubungi Admin sekolah untuk pengecekan penugasan.</p>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>