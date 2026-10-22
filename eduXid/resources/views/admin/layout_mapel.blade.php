<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Setting Layout Urutan Mata Pelajaran Rapor
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            
            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    
                    <p class="text-sm text-gray-600 mb-6">Pilih mata pelajaran untuk setiap urutan cetak di rapor. Total: <strong>{{ $total }} mapel</strong>. Setiap mapel hanya bisa dipilih 1 kali.</p>

                    <form action="{{ route('admin.layoutMapel.store') }}" method="POST">
                        @csrf
                        
                        <div class="space-y-3 max-h-[600px] overflow-y-auto pr-4 border p-4 rounded-lg bg-gray-50">
                            @for ($i = 1; $i <= $total; $i++)
                                <div class="flex items-center gap-4">
                                    <span class="font-bold text-gray-700 w-8 text-center bg-indigo-100 text-indigo-700 rounded py-1">{{ $i }}</span>
                                    <select name="urutan[{{ $i }}]" class="mapel-select border-gray-300 rounded-md shadow-sm w-full p-2 text-sm focus:ring-indigo-500 focus:border-indigo-500" onchange="updateSelects()">
                                        <option value="">- Pilih Mapel untuk Urutan {{ $i }} -</option>
                                        @foreach ($subjects as $s)
                                            @php
                                                // Tandai sebagai selected jika urutan mapel di database sama dengan posisi saat ini
                                                $isSelected = ($s->urutan == $i) ? 'selected' : '';
                                            @endphp
                                            <option value="{{ $s->id }}" {{ $isSelected }}>{{ $s->nama_mata_pelajaran }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endfor
                        </div>

                        <div class="mt-6 flex justify-end">
                            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm shadow-sm transition-colors">
                                💾 Simpan Layout
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Fungsi untuk menyembunyikan mapel yang sudah dipilih pada dropdown lain
        function updateSelects() {
            const selects = document.querySelectorAll('.mapel-select');
            
            // Kumpulkan semua nilai yang sudah dipilih (kecuali yang kosong)
            const selectedValues = Array.from(selects)
                .map(s => s.value)
                .filter(v => v !== '');

            // Looping melalui setiap dropdown
            selects.forEach(select => {
                // Looping melalui setiap option di dalam dropdown
                Array.from(select.options).forEach(option => {
                    if (option.value === '') return; // Abaikan placeholder
                    // Jakah option ini sudah dipilih di dropdown lain, sembunyikan
                    if (selectedValues.includes(option.value) && select.value !== option.value) {
                        option.hidden = true;
                    } else {
                        option.hidden = false;
                    }
                });
            });
        }

        // Jalankan fungsi saat halaman pertama kali dimuat
        document.addEventListener('DOMContentLoaded', function() {
            updateSelects();
        });
    </script>
</x-app-layout>