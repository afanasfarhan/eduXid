<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Data Murid
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    {{ session('success') }}
                </div>
            @endif

            <!-- Baris Pencarian & Tombol Aksi -->
            <div class="mb-4 flex flex-col sm:flex-row justify-between items-center gap-4">

                <form action="{{ route('siswa.index') }}" method="GET" class="flex gap-2 w-full sm:w-auto">
                    <input
                        type="text"
                        name="search"
                        value="{{ $search ?? '' }}"
                        placeholder="Cari semua kolom Murid..."
                        class="border-gray-300 rounded-md shadow-sm p-2 text-sm flex-1 sm:w-64"
                        autocomplete="off"
                    >

                    <input
                        type="hidden"
                        name="sort"
                        value="{{ $sort ?? 'nama' }}"
                    >

                    <input
                        type="hidden"
                        name="direction"
                        value="{{ $direction ?? 'asc' }}"
                    >

                    <button
                        type="submit"
                        class="bg-gray-800 text-white px-4 py-2 rounded-md text-sm font-bold whitespace-nowrap transition-colors hover:bg-gray-900"
                    >
                        Cari
                    </button>

                    @if($search ?? '')
                        <a
                            href="{{ route('siswa.index') }}"
                            class="text-red-500 text-sm py-2 px-2 whitespace-nowrap font-bold hover:text-red-700"
                        >
                            Reset
                        </a>
                    @endif
                </form>

                <div class="flex gap-2 w-full sm:w-auto">

                    {{-- ==========================================================
                         DOWNLOAD DATA 8355
                         Route tetap siswa.export agar tidak merusak route lama.
                         Fungsi export di controller akan menghasilkan Excel 8355.
                    =========================================================== --}}
                    <a
                        href="{{ route('siswa.export', request()->query()) }}"
                        class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 text-sm font-bold whitespace-nowrap flex-1 text-center transition-colors"
                    >
                        Download Data 8355
                    </a>

                    @if(auth()->user()->isAdmin())
                        <button
                            onclick="openModal()"
                            class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 text-sm font-bold whitespace-nowrap flex-1 sm:flex-none transition-colors"
                        >
                            + Tambah Murid
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
                                if ($col == $currentSort) {
                                    return $currentDir == 'asc' ? ' ▲' : ' ▼';
                                }

                                return '';
                            }
                        @endphp

                        <thead class="bg-gray-50">
                            <tr>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    No
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a
                                        href="{{ route('siswa.index', [
                                            'sort' => 'nisn',
                                            'direction' => $sort == 'nisn' && $direction == 'asc' ? 'desc' : 'asc',
                                            'search' => $search ?? ''
                                        ]) }}"
                                        class="flex items-center hover:text-gray-700"
                                    >
                                        NISN {!! sortArrow('nisn', $sort, $direction) !!}
                                    </a>
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a
                                        href="{{ route('siswa.index', [
                                            'sort' => 'nipd',
                                            'direction' => $sort == 'nipd' && $direction == 'asc' ? 'desc' : 'asc',
                                            'search' => $search ?? ''
                                        ]) }}"
                                        class="flex items-center hover:text-gray-700"
                                    >
                                        NIPD {!! sortArrow('nipd', $sort, $direction) !!}
                                    </a>
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a
                                        href="{{ route('siswa.index', [
                                            'sort' => 'nama',
                                            'direction' => $sort == 'nama' && $direction == 'asc' ? 'desc' : 'asc',
                                            'search' => $search ?? ''
                                        ]) }}"
                                        class="flex items-center hover:text-gray-700"
                                    >
                                        Nama Murid {!! sortArrow('nama', $sort, $direction) !!}
                                    </a>
                                </th>

                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                    <a
                                        href="{{ route('siswa.index', [
                                            'sort' => 'jenis_kelamin',
                                            'direction' => $sort == 'jenis_kelamin' && $direction == 'asc' ? 'desc' : 'asc',
                                            'search' => $search ?? ''
                                        ]) }}"
                                        class="flex items-center justify-center hover:text-gray-700"
                                    >
                                        L/P {!! sortArrow('jenis_kelamin', $sort, $direction) !!}
                                    </a>
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    <a
                                        href="{{ route('siswa.index', [
                                            'sort' => 'kelas',
                                            'direction' => $sort == 'kelas' && $direction == 'asc' ? 'desc' : 'asc',
                                            'search' => $search ?? ''
                                        ]) }}"
                                        class="flex items-center hover:text-gray-700"
                                    >
                                        Kelas {!! sortArrow('kelas', $sort, $direction) !!}
                                    </a>
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    Tempat, Tgl Lahir
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    Agama
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    NIK
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    Alamat
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    Nama Ayah
                                </th>

                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                                    Nama Ibu
                                </th>

                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">
                                    Aksi
                                </th>

                            </tr>
                        </thead>

                        <tbody class="bg-white divide-y divide-gray-200">

                            @forelse ($students as $student)

                                <tr data-eduxid-student-id="{{ $student->id }}" data-eduxid-student-nisn="{{ $student->nisn }}" data-eduxid-student-name="{{ $student->nama }}">

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $loop->iteration + ($students->currentPage() - 1) * $students->perPage() }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {{ $student->nisn ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $student->nipd ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ $student->nama ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-center text-sm text-gray-500">
                                        {{ $student->jenis_kelamin ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">

                                        @if($student->kelas)

                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                {{ $student->kelas }}
                                            </span>

                                        @else

                                            <span class="text-gray-400 italic text-xs">
                                                Belum ada kelas
                                            </span>

                                        @endif

                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">

                                        {{ $student->tempat_lahir ?? '-' }},

                                        @if(!empty($student->tanggal_lahir))

                                            {{ \Carbon\Carbon::parse($student->tanggal_lahir)->format('d/m/Y') }}

                                        @else

                                            -

                                        @endif

                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $student->agama ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $student->nik ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $student->alamat_jalan ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $student->nama_ayah ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        {{ $student->nama_ibu ?? '-' }}
                                    </td>

                                    <td class="px-4 py-3 whitespace-nowrap text-center text-sm font-medium">

                                        <button
                                            onclick="openDetailModal(this)"
                                            class="text-gray-600 hover:text-gray-900 font-bold mr-2"

                                            data-nama="{{ $student->nama ?? '' }}"
                                            data-nisn="{{ $student->nisn ?? '' }}"
                                            data-nipd="{{ $student->nipd ?? '' }}"
                                            data-jk="{{ $student->jenis_kelamin ?? '' }}"
                                            data-tempat-lahir="{{ $student->tempat_lahir ?? '' }}"
                                            data-tgl-lahir="{{ $student->tanggal_lahir ?? '' }}"
                                            data-agama="{{ $student->agama ?? '' }}"
                                            data-nik="{{ $student->nik ?? '' }}"
                                            data-alamat="{{ $student->alamat_jalan ?? '' }}"
                                            data-telepon-rumah="{{ $student->nomor_telepon_rumah ?? '' }}"
                                            data-telepon-seluler="{{ $student->nomor_telepon_seluler ?? '' }}"
                                            data-email="{{ $student->email ?? '' }}"
                                            data-sekolah-asal="{{ $student->sekolah_asal ?? '' }}"
                                            data-tgl-masuk="{{ $student->tanggal_masuk_sekolah ?? '' }}"
                                            data-jenis-pendaftaran="{{ $student->jenis_pendaftaran ?? '' }}"
                                            data-nama-ayah="{{ $student->nama_ayah ?? '' }}"
                                            data-pekerjaan-ayah="{{ $student->pekerjaan_ayah ?? '' }}"
                                            data-nama-ibu="{{ $student->nama_ibu ?? '' }}"
                                            data-pekerjaan-ibu="{{ $student->pekerjaan_ibu ?? '' }}"
                                            data-nama-wali="{{ $student->nama_wali ?? '' }}"
                                            data-pekerjaan-wali="{{ $student->pekerjaan_wali ?? '' }}"
                                            data-anak-ke="{{ $student->anak_keberapa ?? '' }}"
                                            data-tinggi-badan="{{ $student->tinggi_badan ?? '' }}"
                                            data-berat-badan="{{ $student->berat_badan ?? '' }}"
                                            data-kebutuhan-khusus="{{ $student->kebutuhan_khusus ?? '' }}"
                                        >
                                            Detail
                                        </button>

                                        @if(auth()->user()->isAdmin())

                                            <button
                                                onclick="editModal(this)"
                                                data-student-id="{{ $student->id }}"
                                                data-student-nisn="{{ $student->nisn }}"
                                                data-student-nipd="{{ $student->nipd }}"
                                                data-student-nama="{{ $student->nama }}"
                                                data-student-jk="{{ $student->jenis_kelamin }}"
                                                data-student-homeroom="{{ $student->enroll_homeroom_id }}"
                                                class="text-indigo-600 hover:text-indigo-900 font-bold mr-2"
                                            >
                                                Edit
                                            </button>

                                            <form
                                                action="{{ route('siswa.destroy', $student->id) }}"
                                                method="POST"
                                                class="inline"
                                                onsubmit="return confirm('Yakin ingin menghapus data ini?')"
                                            >

                                                @csrf
                                                @method('DELETE')

                                                <button
                                                    type="submit"
                                                    class="text-red-600 hover:text-red-900 font-bold"
                                                >
                                                    Hapus
                                                </button>

                                            </form>

                                        @endif

                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td
                                        colspan="13"
                                        class="px-4 py-6 text-center text-sm text-gray-500"
                                    >
                                        Tidak ada data murid ditemukan.
                                    </td>

                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

                <!-- Paginasi -->
                <div class="px-4 py-3 bg-white border-t flex flex-col sm:flex-row justify-between items-center gap-3">

                    <div class="text-sm text-gray-600">
                        Menampilkan
                        {{ $students->firstItem() ?? 0 }}
                        -
                        {{ $students->lastItem() ?? 0 }}
                        dari
                        {{ $students->total() ?? 0 }}
                        data
                    </div>

                    <div>
                        {{ $students->links() }}
                    </div>

                </div>

            </div>

        </div>
    </div>


    <!-- ==========================================================
         MODAL DETAIL SISWA
    =========================================================== -->

    <div
        id="modal-detail-siswa"
        class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden justify-center items-center z-50 p-4"
    >

        <div
            class="bg-white rounded-lg shadow-2xl w-full max-w-4xl p-6 relative my-8 overflow-y-auto max-h-[90vh]"
        >

            <button
                onclick="closeDetailModal()"
                class="absolute top-3 right-3 text-gray-400 hover:text-red-500 text-2xl leading-none"
            >
                &times;
            </button>

            <h3 class="text-lg font-bold mb-4 border-b pb-2">
                Detail Data Murid Dapodik
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 text-sm">

                <div>
                    <strong>Nama Lengkap:</strong><br>
                    <span id="d-nama">-</span>
                </div>

                <div>
                    <strong>NISN:</strong><br>
                    <span id="d-nisn">-</span>
                </div>

                <div>
                    <strong>NIPD:</strong><br>
                    <span id="d-nipd">-</span>
                </div>

                <div>
                    <strong>Jenis Kelamin:</strong><br>
                    <span id="d-jk">-</span>
                </div>

                <div>
                    <strong>Tempat, Tgl Lahir:</strong><br>
                    <span id="d-lahir">-</span>
                </div>

                <div>
                    <strong>Agama:</strong><br>
                    <span id="d-agama">-</span>
                </div>

                <div>
                    <strong>NIK:</strong><br>
                    <span id="d-nik">-</span>
                </div>

                <div>
                    <strong>Alamat:</strong><br>
                    <span id="d-alamat">-</span>
                </div>

                <div>
                    <strong>Telepon Rumah:</strong><br>
                    <span id="d-telepon-rumah">-</span>
                </div>

                <div>
                    <strong>Telepon Seluler:</strong><br>
                    <span id="d-telepon-seluler">-</span>
                </div>

                <div>
                    <strong>Email:</strong><br>
                    <span id="d-email">-</span>
                </div>

                <div>
                    <strong>Sekolah Asal:</strong><br>
                    <span id="d-sekolah-asal">-</span>
                </div>

                <div>
                    <strong>Tanggal Masuk:</strong><br>
                    <span id="d-tgl-masuk">-</span>
                </div>

                <div>
                    <strong>Jenis Pendaftaran:</strong><br>
                    <span id="d-jenis-pendaftaran">-</span>
                </div>

                <div>
                    <strong>Nama Ayah:</strong><br>
                    <span id="d-nama-ayah">-</span>
                </div>

                <div>
                    <strong>Pekerjaan Ayah:</strong><br>
                    <span id="d-pekerjaan-ayah">-</span>
                </div>

                <div>
                    <strong>Nama Ibu:</strong><br>
                    <span id="d-nama-ibu">-</span>
                </div>

                <div>
                    <strong>Pekerjaan Ibu:</strong><br>
                    <span id="d-pekerjaan-ibu">-</span>
                </div>

                <div>
                    <strong>Nama Wali:</strong><br>
                    <span id="d-nama-wali">-</span>
                </div>

                <div>
                    <strong>Pekerjaan Wali:</strong><br>
                    <span id="d-pekerjaan-wali">-</span>
                </div>

                <div>
                    <strong>Anak Ke:</strong><br>
                    <span id="d-anak-ke">-</span>
                </div>

                <div>
                    <strong>Tinggi Badan:</strong><br>
                    <span id="d-tinggi-badan">-</span>
                </div>

                <div>
                    <strong>Berat Badan:</strong><br>
                    <span id="d-berat-badan">-</span>
                </div>

                <div>
                    <strong>Kebutuhan Khusus:</strong><br>
                    <span id="d-kebutuhan-khusus">-</span>
                </div>

            </div>

            <button
                onclick="closeDetailModal()"
                class="mt-4 bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg text-sm font-medium w-full"
            >
                Tutup
            </button>

        </div>

    </div>


    <!-- ==========================================================
         MODAL TAMBAH / EDIT SISWA
         KHUSUS ADMIN
    =========================================================== -->

    @if(auth()->user()->isAdmin())

        <div
            id="modal-siswa-admin"
            class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden justify-center items-center z-50 p-4"
        >

            <div
                class="bg-white rounded-lg shadow-2xl w-full max-w-lg p-6 relative my-8 overflow-y-auto max-h-[90vh]"
            >

                <button
                    onclick="closeModal()"
                    class="absolute top-3 right-3 text-gray-400 hover:text-red-500 text-2xl font-bold leading-none z-10"
                >
                    &times;
                </button>

                <h3
                    id="modal-title"
                    class="text-lg font-bold mb-6 text-gray-800"
                >
                    Tambah Murid
                </h3>

                <form
                    id="form-siswa"
                    method="POST"
                    action=""
                >

                    @csrf

                    <input
                        type="hidden"
                        id="_method"
                        name="_method"
                        value="POST"
                    >

                    <div class="grid grid-cols-2 gap-4 mb-4">

                        <div>

                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                NISN
                            </label>

                            <input
                                type="text"
                                id="nisn"
                                name="nisn"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                required
                            >

                        </div>

                        <div>

                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                NIPD
                            </label>

                            <input
                                type="text"
                                id="nipd"
                                name="nipd"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                            >

                        </div>

                    </div>

                    <div class="mb-4">

                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Nama Lengkap
                        </label>

                        <input
                            type="text"
                            id="nama"
                            name="nama"
                            class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                            required
                        >

                    </div>

                    <div class="grid grid-cols-2 gap-4 mb-6">

                        <div>

                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Jenis Kelamin
                            </label>

                            <select
                                id="jenis_kelamin"
                                name="jenis_kelamin"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                                required
                            >

                                <option value="L">
                                    Laki-laki
                                </option>

                                <option value="P">
                                    Perempuan
                                </option>

                            </select>

                        </div>

                        <div>

                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Kelas (Reguler)
                            </label>

                            <select
                                id="homeroom_id"
                                name="homeroom_id"
                                class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm"
                            >

                                <option value="">
                                    -- Tidak Ada --
                                </option>

                                @foreach ($rombels as $r)

                                    <option value="{{ $r->id }}">
                                        {{ $r->nama }}
                                    </option>

                                @endforeach

                            </select>

                        </div>

                    </div>

                    <button
                        type="submit"
                        class="w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 font-bold text-sm transition-colors"
                    >
                        Simpan
                    </button>

                </form>

            </div>

        </div>


        <script>

            function openModal() {

                document.getElementById('modal-title').innerText = 'Tambah Murid';

                document.getElementById('form-siswa').action =
                    "{{ route('siswa.store') }}";

                document.getElementById('_method').value = 'POST';

                document.getElementById('nisn').value = '';
                document.getElementById('nipd').value = '';
                document.getElementById('nama').value = '';
                document.getElementById('jenis_kelamin').value = 'L';
                document.getElementById('homeroom_id').value = '';

                document.getElementById('modal-siswa-admin').classList.remove('hidden');
                document.getElementById('modal-siswa-admin').classList.add('flex');
            }


            function editModal(button) {
                const data = button.dataset;
                const id = data.studentId;
                const nisn = data.studentNisn || '';
                const nipd = data.studentNipd || '';
                const nama = data.studentNama || '';
                const jk = data.studentJk || '';
                const homeroomId = data.studentHomeroom || '';
                document.getElementById('modal-title').innerText = 'Edit Murid';
                // Admin route, not /siswa/{id}/update (404). Server-generated path respects URL prefix.
                document.getElementById('form-siswa').action =
                    @json(route('siswa.update', ['id' => '__STUDENT_ID__'])).replace('__STUDENT_ID__', encodeURIComponent(id));

                document.getElementById('_method').value = 'PUT';

                document.getElementById('nisn').value = nisn;
                document.getElementById('nipd').value = nipd || '';
                document.getElementById('nama').value = nama;
                document.getElementById('jenis_kelamin').value = jk;
                document.getElementById('homeroom_id').value =
                    homeroomId || '';

                document.getElementById('modal-siswa-admin').classList.remove('hidden');
                document.getElementById('modal-siswa-admin').classList.add('flex');
            }


            function closeModal() {

                document.getElementById('modal-siswa-admin').classList.add('hidden');
                document.getElementById('modal-siswa-admin').classList.remove('flex');
            }


            function openDetailModal(el) {

                document.getElementById('d-nama').innerText =
                    el.dataset.nama || '-';

                document.getElementById('d-nisn').innerText =
                    el.dataset.nisn || '-';

                document.getElementById('d-nipd').innerText =
                    el.dataset.nipd || '-';

                document.getElementById('d-jk').innerText =
                    el.dataset.jk || '-';


                let tglLahir = el.dataset.tglLahir
                    ? new Date(el.dataset.tglLahir).toLocaleDateString(
                        'id-ID',
                        {
                            day: '2-digit',
                            month: 'long',
                            year: 'numeric'
                        }
                    )
                    : '-';

                document.getElementById('d-lahir').innerText =
                    (el.dataset.tempatLahir || '-') +
                    ', ' +
                    tglLahir;


                document.getElementById('d-agama').innerText =
                    el.dataset.agama || '-';

                document.getElementById('d-nik').innerText =
                    el.dataset.nik || '-';

                document.getElementById('d-alamat').innerText =
                    el.dataset.alamat || '-';

                document.getElementById('d-telepon-rumah').innerText =
                    el.dataset.teleponRumah || '-';

                document.getElementById('d-telepon-seluler').innerText =
                    el.dataset.teleponSeluler || '-';

                document.getElementById('d-email').innerText =
                    el.dataset.email || '-';

                document.getElementById('d-sekolah-asal').innerText =
                    el.dataset.sekolahAsal || '-';


                let tglMasuk = el.dataset.tglMasuk
                    ? new Date(el.dataset.tglMasuk).toLocaleDateString(
                        'id-ID',
                        {
                            day: '2-digit',
                            month: 'long',
                            year: 'numeric'
                        }
                    )
                    : '-';

                document.getElementById('d-tgl-masuk').innerText =
                    tglMasuk;


                document.getElementById('d-jenis-pendaftaran').innerText =
                    el.dataset.jenisPendaftaran || '-';

                document.getElementById('d-nama-ayah').innerText =
                    el.dataset.namaAyah || '-';

                document.getElementById('d-pekerjaan-ayah').innerText =
                    el.dataset.pekerjaanAyah || '-';

                document.getElementById('d-nama-ibu').innerText =
                    el.dataset.namaIbu || '-';

                document.getElementById('d-pekerjaan-ibu').innerText =
                    el.dataset.pekerjaanIbu || '-';

                document.getElementById('d-nama-wali').innerText =
                    el.dataset.namaWali || '-';

                document.getElementById('d-pekerjaan-wali').innerText =
                    el.dataset.pekerjaanWali || '-';

                document.getElementById('d-anak-ke').innerText =
                    el.dataset.anakKe || '-';

                document.getElementById('d-tinggi-badan').innerText =
                    el.dataset.tinggiBadan || '-';

                document.getElementById('d-berat-badan').innerText =
                    el.dataset.beratBadan || '-';

                document.getElementById('d-kebutuhan-khusus').innerText =
                    el.dataset.kebutuhanKhusus || '-';


                document
                    .getElementById('modal-detail-siswa')
                    .classList.remove('hidden');

                document
                    .getElementById('modal-detail-siswa')
                    .classList.add('flex');
            }


            function closeDetailModal() {

                document
                    .getElementById('modal-detail-siswa')
                    .classList.add('hidden');

                document
                    .getElementById('modal-detail-siswa')
                    .classList.remove('flex');
            }

        </script>

    @endif

</x-app-layout>