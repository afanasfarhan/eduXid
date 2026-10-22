<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Penilaian Kokurikuler
        </h2>
    </x-slot>
<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        @if (session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if(count($kokurikulers) > 0)

            @if(!$selectedKokurikuler)

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                    @foreach ($kokurikulers as $k)

                        <a
                            href="{{ route('guru.kokurikuler', ['id' => $k->id]) }}"
                            class="bg-white rounded-xl shadow-md overflow-hidden flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1 border border-gray-100"
                        >

                            <div class="bg-indigo-50 p-4 border-b border-indigo-100 flex justify-between items-center">

                                <div>
                                    <h3 class="text-lg font-bold text-indigo-900 leading-tight">
                                        {{ $k->nama_kegiatan }}
                                    </h3>
                                </div>

                                <span class="bg-white text-indigo-600 border border-indigo-200 px-3 py-1 rounded-full text-sm font-bold shadow-sm">
                                    {{ $k->kelas }}
                                </span>

                            </div>

                            <div class="p-4 flex-grow flex flex-col justify-center">

                                <p class="text-sm text-gray-500 mb-1">
                                    Tema:
                                </p>

                                <p class="text-md font-medium text-gray-700">
                                    {{ $k->tema }}
                                </p>

                            </div>

                        </a>

                    @endforeach

                </div>

            @else

                <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100 mb-6 transition-all duration-300">

                    <div class="bg-indigo-50 p-4 border-b border-indigo-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">

                        <div>

                            <h3 class="text-lg font-bold text-indigo-900 leading-tight">
                                Kegiatan:
                                {{ $selectedKokurikuler->nama_kegiatan }}
                            </h3>

                            <p class="text-sm text-indigo-500 mt-1">
                                Kelas:
                                {{ $selectedKokurikuler->kelas }}
                                |
                                Tema:
                                {{ $selectedKokurikuler->tema }}
                            </p>

                        </div>

                        <a
                            href="{{ route('guru.kokurikuler') }}"
                            class="bg-white text-indigo-600 border border-indigo-200 px-4 py-2 rounded-md hover:bg-indigo-100 text-sm font-bold whitespace-nowrap shadow-sm"
                        >
                            ← Ganti Kelas
                        </a>

                    </div>

                    <div class="p-4">

                        <form
                            action="{{ route('guru.kokurikuler.store') }}"
                            method="POST"
                            id="form-kokurikuler"
                        >

                            @csrf

                            <input
                                type="hidden"
                                name="kokurikuler_id"
                                value="{{ $selectedKokurikuler->id }}"
                            >

                            <div class="overflow-x-auto">

                                <table
                                    class="min-w-full divide-y divide-gray-200 table-fixed"
                                    id="tabel-kokurikuler"
                                >

                                    <thead class="bg-gray-50">

                                        <tr>

                                            <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-12">
                                                No
                                            </th>

                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-40">
                                                Nama Murid
                                            </th>

                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                Penilaian 8 Dimensi
                                            </th>

                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                Deskripsi
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody class="bg-white divide-y divide-gray-200">

                                        @foreach ($students as $s)

                                            <tr>

                                                <td class="px-2 py-3 text-center text-sm text-gray-500 nomor-urut">
                                                    {{ $loop->iteration }}
                                                </td>

                                                <td class="px-4 py-3 text-sm font-medium text-gray-900">

                                                    <div class="truncate">
                                                        {{ mb_convert_case(strtolower($s->nama), MB_CASE_TITLE, 'UTF-8') }}
                                                    </div>

                                                    <input
                                                        type="hidden"
                                                        name="students[{{ $s->id }}][student_id]"
                                                        value="{{ $s->id }}"
                                                    >

                                                </td>

                                                <td class="px-2 py-3 text-center">

                                                    <div class="grid grid-cols-2 gap-1">

                                                        @php

                                                            $dimensi = [
                                                                1 => 'Keimanan',
                                                                2 => 'Kewargaan',
                                                                3 => 'Penalaran',
                                                                4 => 'Kreativitas',
                                                                5 => 'Kolaborasi',
                                                                6 => 'Kemandirian',
                                                                7 => 'Kesehatan',
                                                                8 => 'Komunikasi'
                                                            ];

                                                        @endphp

                                                        @foreach($dimensi as $i => $nama)

                                                            @php
                                                                /*
                                                                 * DEFAULT NILAI = 3 BINTANG
                                                                 *
                                                                 * Jika database belum memiliki
                                                                 * nilai dimensi tersebut,
                                                                 * otomatis tampil 3 bintang.
                                                                 */
                                                                $nilaiDimensi =
                                                                    isset($s->{'d'.$i})
                                                                        && is_numeric($s->{'d'.$i})
                                                                        ? (int) $s->{'d'.$i}
                                                                        : 3;

                                                                /*
                                                                 * Pastikan tetap berada
                                                                 * pada rentang 1-5.
                                                                 */
                                                                $nilaiDimensi =
                                                                    max(
                                                                        1,
                                                                        min(
                                                                            5,
                                                                            $nilaiDimensi
                                                                        )
                                                                    );
                                                            @endphp

                                                            <div class="flex flex-col items-center bg-gray-50 p-1 rounded">

                                                                <span class="text-xs text-gray-600 leading-tight mb-0.5">
                                                                    {{ $i }}. {{ $nama }}
                                                                </span>

                                                                <div
                                                                    class="rating-stars flex space-x-0.5"
                                                                    data-student-id="{{ $s->id }}"
                                                                    data-dim="d{{ $i }}"
                                                                >

                                                                    <input
                                                                        type="hidden"
                                                                        name="students[{{ $s->id }}][d{{ $i }}]"
                                                                        value="{{ $nilaiDimensi }}"
                                                                        class="sikap-input"
                                                                    >

                                                                    @for($j = 1; $j <= 5; $j++)

                                                                        <span
                                                                            class="star text-xl cursor-pointer {{ $j <= $nilaiDimensi ? 'text-yellow-400' : 'text-gray-300' }} hover:text-yellow-400"
                                                                            data-val="{{ $j }}"
                                                                        >
                                                                            ★
                                                                        </span>

                                                                    @endfor

                                                                </div>

                                                            </div>

                                                        @endforeach

                                                    </div>

                                                </td>

                                                <td class="px-4 py-3 text-center">

                                                    <textarea
                                                        name="students[{{ $s->id }}][deskripsi]"
                                                        rows="10"
                                                        class="w-full border-gray-300 rounded-md shadow-sm text-sm p-1"
                                                        placeholder="Deskripsi..."
                                                    >{{ $s->deskripsi ?? '' }}</textarea>

                                                </td>

                                            </tr>

                                        @endforeach

                                    </tbody>

                                </table>

                            </div>

                            <div class="p-4 border-t border-gray-100 text-right">

                                <button
                                    type="submit"
                                    class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 font-bold text-sm shadow-sm transition-colors"
                                >
                                    Simpan Nilai
                                </button>

                            </div>

                        </form>

                    </div>

                </div>

            @endif

        @else

            <div class="bg-white rounded-xl shadow-md border border-gray-100 p-12 text-center transition-all duration-300">

                <div class="text-6xl mb-4">
                    🚫
                </div>

                <h3 class="text-xl font-bold text-gray-800 mb-2">
                    Bukan Koordinator Kokurikuler
                </h3>

                <p class="text-gray-500">
                    Akun Anda tidak terdaftar sebagai koordinator kegiatan kokurikuler.
                </p>

            </div>

        @endif

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        document.body.addEventListener('click', function (e) {

            if (e.target.classList.contains('star')) {

                const container =
                    e.target.closest('.rating-stars');

                if (!container) {
                    return;
                }

                const val =
                    parseInt(
                        e.target.dataset.val
                    );

                const input =
                    container.querySelector(
                        '.sikap-input'
                    );

                /*
                 * Simpan nilai yang dipilih
                 * ke input hidden.
                 */
                if (input) {
                    input.value = val;
                }

                /*
                 * Perbarui tampilan bintang.
                 */
                const stars =
                    container.querySelectorAll(
                        '.star'
                    );

                stars.forEach(function (star) {

                    const starValue =
                        parseInt(
                            star.dataset.val
                        );

                    if (starValue <= val) {

                        star.classList.remove(
                            'text-gray-300'
                        );

                        star.classList.add(
                            'text-yellow-400'
                        );

                    } else {

                        star.classList.remove(
                            'text-yellow-400'
                        );

                        star.classList.add(
                            'text-gray-300'
                        );
                    }

                });

            }

        });

    });
</script>
</x-app-layout>
