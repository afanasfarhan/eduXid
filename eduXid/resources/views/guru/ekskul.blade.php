<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pembina Ekstrakurikuler
        </h2>
    </x-slot>
<div class="py-12">

    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        @if (session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                {{ session('error') }}
            </div>
        @endif

        @if($ekskul)

            <!-- ==========================================
                 MANAJEMEN ANGGOTA EKSKUL
                 ========================================== -->

            <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100 mb-6 transition-all duration-300">

                <div class="bg-yellow-50 p-4 border-b border-yellow-100">

                    <h3 class="text-lg font-bold text-yellow-900">
                        Manajemen Anggota Ekstrakurikuler
                    </h3>

                </div>

                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- TAMBAH ANGGOTA -->

                    <div>

                        <h4 class="font-bold text-gray-700 mb-2 text-sm">
                            Tambah Anggota Baru
                        </h4>

                        <form
                            action="{{ route('guru.ekskul.addMember') }}"
                            method="POST"
                            class="flex gap-2"
                        >

                            @csrf

                            <select
                                name="student_id"
                                class="flex-1 border-gray-300 rounded-md shadow-sm text-sm"
                                required
                            >

                                <option value="">
                                    -- Pilih Murid --
                                </option>

                                @foreach($availableStudents as $av)

                                    <option value="{{ $av->id }}">
                                        {{ $av->nama }}
                                        ({{ $av->nisn }})
                                    </option>

                                @endforeach

                            </select>

                            <button
                                type="submit"
                                class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 text-sm font-bold whitespace-nowrap"
                            >
                                Tambah
                            </button>

                        </form>

                    </div>

                    <!-- HAPUS ANGGOTA -->

                    <div>

                        <h4 class="font-bold text-gray-700 mb-2 text-sm">
                            Keluarkan Anggota
                        </h4>

                        <div class="max-h-40 overflow-y-auto border border-gray-200 rounded-md p-2 space-y-1 bg-gray-50">

                            @foreach($students as $s)

                                <div class="flex justify-between items-center text-sm p-1 hover:bg-white rounded">

                                    <span class="truncate">
                                        {{ $s->nama }}
                                    </span>

                                    <form
                                        action="{{ route('guru.ekskul.removeMember', $s->id) }}"
                                        method="POST"
                                        onsubmit="return confirm('Keluarkan murid ini dari ekskul? Nilai terkait juga akan dihapus.')"
                                    >

                                        @csrf

                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="text-red-500 hover:text-red-700 text-xs font-bold"
                                        >
                                            Hapus
                                        </button>

                                    </form>

                                </div>

                            @endforeach

                            @if($students->isEmpty())

                                <p class="text-center text-gray-400 text-xs py-4">
                                    Belum ada anggota.
                                </p>

                            @endif

                        </div>

                    </div>

                </div>

            </div>


            <!-- ==========================================
                 PENILAIAN EKSTRAKURIKULER
                 ========================================== -->

            <div class="bg-white rounded-xl shadow-md overflow-hidden border border-gray-100 mb-6 transition-all duration-300">

                <div class="bg-indigo-50 p-4 border-b border-indigo-100 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">

                    <div>

                        <h3 class="text-lg font-bold text-indigo-900 leading-tight">
                            Ekstrakurikuler:
                            {{ $ekskul->nama }}
                        </h3>

                        <p class="text-sm text-indigo-500 mt-1">
                            Silakan berikan penilaian 8 dimensi dan deskripsi capaian murid.
                        </p>

                    </div>

                </div>

                <div class="p-4">

                    @if($students->isEmpty())

                        <div class="text-center text-gray-500 py-8">

                            <p>
                                Belum ada anggota ekskul.
                                Silakan tambah anggota di atas.
                            </p>

                        </div>

                    @else

                        <form
                            action="{{ route('guru.ekskul.store') }}"
                            method="POST"
                            id="form-ekskul"
                        >

                            @csrf

                            <div class="overflow-x-auto">

                                <table
                                    class="min-w-full divide-y divide-gray-200 table-fixed"
                                    id="tabel-ekskul"
                                >

                                    <thead class="bg-gray-50">

                                        <tr>

                                            <th class="px-2 py-3 text-center text-xs font-medium text-gray-500 uppercase w-12">
                                                No
                                            </th>

                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-48">
                                                Nama Murid
                                            </th>

                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                Nilai
                                            </th>

                                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                                Deskripsi Capaian
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
                                                                 * 3 = Mulai Berkembang
                                                                 */
                                                                $nilaiDimensi = $s->{'d'.$i} ?? 3;
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
                                                                            title="{{ $j }} bintang"
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
                                                        placeholder="Deskripsi capaian..."
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

                    @endif

                </div>

            </div>

        @else

            <div class="bg-white rounded-xl shadow-md border border-gray-100 p-12 text-center transition-all duration-300">

                <div class="text-6xl mb-4">
                    🚫
                </div>

                <h3 class="text-xl font-bold text-gray-800 mb-2">
                    Bukan Pembina Ekskul
                </h3>

                <p class="text-gray-500">
                    Akun Anda tidak terdaftar sebagai Pembina Ekstrakurikuler.
                    Jika Anda merasa ini adalah kesalahan, silakan hubungi Admin sekolah.
                </p>

            </div>

        @endif

    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        document.body.addEventListener('click', function (e) {

            if (!e.target.classList.contains('star')) {
                return;
            }

            const container =
                e.target.closest('.rating-stars');

            if (!container) {
                return;
            }

            const val =
                parseInt(e.target.dataset.val);

            const input =
                container.querySelector('.sikap-input');

            if (input) {
                input.value = val;
            }

            const stars =
                container.querySelectorAll('.star');

            stars.forEach(function (star) {

                const starValue =
                    parseInt(star.dataset.val);

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

        });

    });
</script>
</x-app-layout>
