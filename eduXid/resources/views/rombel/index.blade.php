<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Denah Kelas
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach ($rombels as $r)
                    <a href="{{ route('rombel.show', $r->id) }}" class="block bg-white p-6 rounded-lg shadow-sm border border-gray-100 hover:shadow-md hover:border-indigo-500 transition-all">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-bold text-gray-800">{{ $r->nama }}</h3>
                            <span class="px-3 py-1 bg-indigo-100 text-indigo-700 rounded-full text-xs font-semibold">{{ $r->tingkat_pendidikan }}</span>
                        </div>
                        <p class="text-sm text-gray-500">Wali Kelas:</p>
<p class="text-md font-medium text-gray-700">
    @if($r->wali_kelas)
        {{ $r->gelar_depan ? $r->gelar_depan . ' ' : '' }}{{ mb_convert_case(strtolower($r->wali_kelas), MB_CASE_TITLE, 'UTF-8') }}{{ $r->gelar_belakang ? ', ' . $r->gelar_belakang : '' }}
    @else
        Belum ada
    @endif
</p>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-app-layout>