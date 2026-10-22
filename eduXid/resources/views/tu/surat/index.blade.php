<x-app-layout>

    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Generator Surat Dinas
        </h2>
    </x-slot>

    @php

        $jenisSurat = [
            'Surat Undangan',
            'Surat Panggilan Orang Tua/Wali',
            'Surat Tugas',
            'Surat Keterangan Murid',
            'Surat Keterangan Aktif',
            'Surat Keterangan Guru',
            'Surat Keterangan',
            'Surat Rekomendasi',
            'Surat Pernyataan',
            'Surat Edaran',
            'Surat Teguran',
            'Surat Peringatan',
            'Surat Permohonan',
            'Surat Pemberitahuan',
            'Surat Pengantar',
            'Surat Dinas',
            'Surat Lainnya',
        ];

        /*
         * Data seluruh GTK
         */
        $teachersJson = $teachers
            ->map(function ($t) use ($namaSekolah) {
                return [
                    'id' => $t->id,
                    'nama' => $t->nama_lengkap,
                    'nip' => $t->nip ?? '',
                    'pangkat' => $t->pangkat_lengkap,
                    'jabatan' => $t->jabatan_ptk ?? '',
                    'unit' => $namaSekolah,
                ];
            })
            ->values();

        /*
         * Data Kepala Sekolah
         */
        $kepseksJson = $kepseks
            ->map(function ($t) use ($namaSekolah) {
                return [
                    'id' => $t->id,
                    'nama' => $t->nama_lengkap,
                    'nip' => $t->nip ?? '',
                    'pangkat' => $t->pangkat_lengkap,
                    'jabatan' => $t->jabatan_ptk ?? 'Kepala Sekolah',
                    'unit' => $namaSekolah,
                ];
            })
            ->values();

        /*
         * Data Guru BK
         */
        $guruBksJson = $guruBks
            ->map(function ($t) use ($namaSekolah) {
                return [
                    'id' => $t->id,
                    'nama' => $t->nama_lengkap,
                    'nip' => $t->nip ?? '',
                    'pangkat' => $t->pangkat_lengkap,
                    'jabatan' => $t->jabatan_ptk ?? 'Guru BK',
                    'unit' => $namaSekolah,
                ];
            })
            ->values();

    @endphp

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('info'))
                <div class="mb-4 bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg">
                    {{ session('info') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                    {{ session('error') }}
                </div>
            @endif

            <form id="suratForm" action="{{ route('tu.surat.cetak') }}" method="POST" enctype="multipart/form-data">
                @csrf

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                    {{-- =====================================================
                         PANEL UTAMA
                    ====================================================== --}}
                    <div class="lg:col-span-2 space-y-6">

                        {{-- INFORMASI SURAT --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 class="font-semibold text-lg text-gray-800 mb-5 pb-3 border-b">
                                Informasi Surat
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Jenis Surat <span class="text-red-500">*</span>
                                    </label>
                                    <select name="jenis_surat" id="jenis_surat" class="w-full rounded-lg border-gray-300" required>
                                        <option value="">-- Pilih Jenis --</option>
                                        @foreach ($jenisSurat as $jenis)
                                            <option value="{{ $jenis }}" @selected(old('jenis_surat') === $jenis)>{{ $jenis }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Nomor Surat <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="nomor_surat" id="nomor_surat" value="{{ old('nomor_surat') }}" class="w-full rounded-lg border-gray-300" required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Tanggal Surat <span class="text-red-500">*</span>
                                    </label>
                                    <input type="date" name="tanggal_surat" id="tanggal_surat" value="{{ old('tanggal_surat', date('Y-m-d')) }}" class="w-full rounded-lg border-gray-300" required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Tempat Surat</label>
                                    <input type="text" name="tempat_surat" id="tempat_surat" value="{{ old('tempat_surat', $tempatSuratDefault) }}" class="w-full rounded-lg border-gray-300">
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Lampiran</label>
                                    <input type="text" name="lampiran" id="lampiran" value="{{ old('lampiran') }}" class="w-full rounded-lg border-gray-300" placeholder="1 (satu) lembar">
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Perihal <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" name="perihal" id="perihal" value="{{ old('perihal') }}" class="w-full rounded-lg border-gray-300" required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Kepada Yth.</label>
                                    <input type="text" name="tujuan" id="tujuan" value="{{ old('tujuan') }}" class="w-full rounded-lg border-gray-300" placeholder="Orang Tua/Wali Murid">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Alamat Tujuan</label>
                                    <input type="text" name="alamat_tujuan" id="alamat_tujuan" value="{{ old('alamat_tujuan') }}" class="w-full rounded-lg border-gray-300" placeholder="di Tempat">
                                </div>
                            </div>
                        </div>

                        {{-- DATA PENERIMA --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 class="font-semibold text-lg text-gray-800 mb-5 pb-3 border-b">Data Penerima Surat</h3>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nama Penerima</label>
                                <select name="penerima_id" id="penerima_id" class="w-full rounded-lg border-gray-300">
                                    <option value="">-- Pilih Penerima --</option>
                                    <optgroup label="Murid">
                                        @foreach ($students as $student)
                                            @php
                                                $kelasSiswa = $student->kelas_rombel ?? '';
                                                $waliKelasId = $student->wali_kelas_id ?? '';
                                            @endphp
                                            <option value="siswa_{{ $student->id }}" data-tipe="siswa" data-nama="{{ $student->nama ?? '' }}" data-nipd="{{ $student->nipd ?? '' }}" data-nisn="{{ $student->nisn ?? '' }}" data-kelas="{{ $kelasSiswa }}" data-wali-kelas-id="{{ $waliKelasId }}" @selected(old('penerima_id') === 'siswa_' . $student->id)>
                                                {{ $student->nama ?? '' }} @if ($kelasSiswa) - {{ $kelasSiswa }} @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="GTK">
                                        @foreach ($teachers as $teacher)
                                            <option value="guru_{{ $teacher->id }}" data-tipe="guru" data-nama="{{ $teacher->nama_lengkap }}" data-nip="{{ $teacher->nip ?? '' }}" data-pangkat="{{ $teacher->pangkat_lengkap }}" data-jabatan="{{ $teacher->jabatan_ptk ?? '' }}" data-unit="{{ $namaSekolah }}" @selected(old('penerima_id') === 'guru_' . $teacher->id)>
                                                {{ $teacher->nama_lengkap }}
                                            </option>
                                        @endforeach
                                    </optgroup>
                                </select>
                            </div>

                            <input type="hidden" name="nama_siswa" id="hidden_nama_siswa">
                            <input type="hidden" name="nis" id="hidden_nis">
                            <input type="hidden" name="nisn" id="hidden_nisn">
                            <input type="hidden" name="nipd" id="hidden_nipd">
                            <input type="hidden" name="kelas" id="hidden_kelas">

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label id="lbl_id1" class="block text-xs font-medium text-gray-500 mb-1">Identitas 1</label>
                                    <input type="text" id="id1" class="w-full rounded-lg border-gray-300 bg-gray-100" readonly>
                                </div>
                                <div>
                                    <label id="lbl_id2" class="block text-xs font-medium text-gray-500 mb-1">Identitas 2</label>
                                    <input type="text" id="id2" class="w-full rounded-lg border-gray-300 bg-gray-100" readonly>
                                </div>
                                <div>
                                    <label id="lbl_id3" class="block text-xs font-medium text-gray-500 mb-1">Identitas 3</label>
                                    <input type="text" id="id3" class="w-full rounded-lg border-gray-300 bg-gray-100" readonly>
                                </div>
                            </div>
                        </div>

                        {{-- DETAIL KEGIATAN --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 class="font-semibold text-lg text-gray-800 mb-5 pb-3 border-b">Detail Kegiatan</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Hari</label>
                                    <input type="text" name="hari_kegiatan" id="hari_kegiatan" value="{{ old('hari_kegiatan') }}" class="w-full rounded-lg border-gray-300 bg-gray-100" readonly>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                                    <input type="date" name="tanggal_kegiatan" id="tanggal_kegiatan" value="{{ old('tanggal_kegiatan', date('Y-m-d')) }}" class="w-full rounded-lg border-gray-300">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Waktu Mulai</label>
                                    <input type="time" name="waktu_mulai" id="waktu_mulai" value="{{ old('waktu_mulai', '08:00') }}" class="w-full rounded-lg border-gray-300">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Waktu Selesai</label>
                                    <input type="time" name="waktu_selesai" id="waktu_selesai" value="{{ old('waktu_selesai', '12:00') }}" class="w-full rounded-lg border-gray-300">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Tempat Kegiatan</label>
                                    <input type="text" name="tempat" id="tempat" value="{{ old('tempat') }}" class="w-full rounded-lg border-gray-300">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Acara/Kegiatan</label>
                                    <input type="text" name="acara" id="acara" value="{{ old('acara') }}" class="w-full rounded-lg border-gray-300">
                                </div>
                            </div>
                        </div>

                        {{-- ISI SURAT --}}
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <div class="flex justify-between items-center mb-4">
                                <h3 class="font-semibold text-lg text-gray-800">Isi Surat</h3>
                                <div class="flex gap-2">
                                    <button type="button" id="btnGenerate" class="bg-yellow-500 text-white px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-yellow-600">Generate</button>
                                    <button type="button" id="btnClear" class="bg-gray-200 text-gray-700 px-3 py-1.5 rounded-lg text-sm font-medium hover:bg-gray-300">Clear</button>
                                </div>
                            </div>
                            <textarea name="isi_surat" id="isi_surat" rows="12" class="w-full rounded-lg border-gray-300 font-mono text-sm focus:border-blue-500" required placeholder="Klik Generate untuk membuat isi surat otomatis...">{{ old('isi_surat') }}</textarea>
                        </div>
                    </div>

                    {{-- =====================================================
                         PANEL PENANDATANGAN
                    ====================================================== --}}
                    <div class="lg:col-span-1 space-y-6">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 sticky top-6">
                            <h3 class="font-semibold text-lg text-gray-800 mb-5 pb-3 border-b">Penandatangan</h3>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Jabatan <span class="text-red-500">*</span>
                                </label>
                                <select name="penandatangan_jabatan" id="penandatangan_jabatan" class="w-full rounded-lg border-gray-300" required>
                                    <option value="">-- Pilih Jabatan --</option>
                                    @foreach (['Kepala Sekolah', 'Wali Kelas', 'Guru BK'] as $jab)
                                        <option value="{{ $jab }}" @selected(old('penandatangan_jabatan') === $jab)>{{ $jab }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Nama <span class="text-red-500">*</span>
                                </label>
                                <select name="penandatangan_id" id="penandatangan_id" class="w-full rounded-lg border-gray-300" required>
                                    <option value="">-- Pilih Nama --</option>
                                </select>
                            </div>

                            <input type="hidden" name="nama_penanda_tangan" id="hidden_nama_ttd">
                            <input type="hidden" name="nip_penanda_tangan" id="hidden_nip_ttd">
                            <input type="hidden" name="pangkat_golongan" id="hidden_pangkat_ttd">
                            <input type="hidden" name="penandatangan_pangkat_golongan" id="hidden_pangkat_ttd_compat">

                            <button type="submit" id="btnCetak" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg transition shadow-sm mt-4">
                                Simpan & Cetak
                            </button>
                            <a href="{{ route('tu.surat.riwayat') }}" class="block text-center mt-3 text-sm text-blue-600 hover:underline">Lihat Riwayat</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var teachers = @json($teachersJson);
            var kepseks = @json($kepseksJson);
            var guruBks = @json($guruBksJson);
            var specialLetterTypes = @json($specialLetterTypes);
            var namaSekolah = @json($namaSekolah);

            function $(id) { return document.getElementById(id); }

            var el = {
                jenisSurat: $('jenis_surat'),
                nomorSurat: $('nomor_surat'),
                tanggalSurat: $('tanggal_surat'),
                tempatSurat: $('tempat_surat'),
                lampiran: $('lampiran'),
                perihal: $('perihal'),
                penerima: $('penerima_id'),
                hariKegiatan: $('hari_kegiatan'),
                tanggalKegiatan: $('tanggal_kegiatan'),
                waktuMulai: $('waktu_mulai'),
                waktuSelesai: $('waktu_selesai'),
                tempat: $('tempat'),
                acara: $('acara'),
                tujuan: $('tujuan'),
                alamatTujuan: $('alamat_tujuan'),
                jabatan: $('penandatangan_jabatan'),
                penandatangan: $('penandatangan_id'),
                isiSurat: $('isi_surat'),
                lbl1: $('lbl_id1'),
                lbl2: $('lbl_id2'),
                lbl3: $('lbl_id3'),
                id1: $('id1'),
                id2: $('id2'),
                id3: $('id3'),
                hNamaSiswa: $('hidden_nama_siswa'),
                hNis: $('hidden_nis'),
                hNisn: $('hidden_nisn'),
                hNipd: $('hidden_nipd'),
                hKelas: $('hidden_kelas'),
                hNamaTtd: $('hidden_nama_ttd'),
                hNipTtd: $('hidden_nip_ttd'),
                hPangkatTtd: $('hidden_pangkat_ttd'),
                hPangkatTtdCompat: $('hidden_pangkat_ttd_compat'),
            };

            var bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            var hariList = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

            function tanggalIndonesia(value) {
                if (!value) return '';
                var parts = value.split('-');
                if (parts.length !== 3) return value;
                return parseInt(parts[2]) + ' ' + bulan[parseInt(parts[1]) - 1] + ' ' + parts[0];
            }

            function hariIndonesia(value) {
                if (!value) return '';
                var date = new Date(value + 'T00:00:00');
                return hariList[date.getDay()];
            }

            function getSubjectData() {
                if (!el.penerima || el.penerima.selectedIndex < 1) {
                    return { tipe: '', nama: '', id1: '', id2: '', id3: '', waliKelasId: '', unit: '' };
                }
                var opt = el.penerima.options[el.penerima.selectedIndex];
                var tipe = opt.dataset.tipe || '';

                if (tipe === 'siswa') {
                    return {
                        tipe: 'siswa',
                        nama: opt.dataset.nama || '',
                        id1: opt.dataset.nipd || '',
                        id2: opt.dataset.nisn || '',
                        id3: opt.dataset.kelas || '',
                        waliKelasId: opt.dataset.waliKelasId || '',
                        unit: namaSekolah
                    };
                }

                return {
                    tipe: 'guru',
                    nama: opt.dataset.nama || '',
                    id1: opt.dataset.nip || '',
                    id2: opt.dataset.pangkat || '',
                    id3: opt.dataset.jabatan || '',
                    waliKelasId: '',
                    unit: opt.dataset.unit || namaSekolah
                };
            }

            function updateSubject() {
                var d = getSubjectData();
                if (!d.tipe) {
                    el.lbl1.textContent = 'Identitas 1';
                    el.lbl2.textContent = 'Identitas 2';
                    el.lbl3.textContent = 'Identitas 3';
                    el.id1.value = ''; el.id2.value = ''; el.id3.value = '';
                    el.hNamaSiswa.value = ''; el.hNis.value = ''; el.hNisn.value = ''; el.hNipd.value = ''; el.hKelas.value = '';
                    return;
                }

                if (d.tipe === 'siswa') {
                    el.lbl1.textContent = 'NIPD'; el.id1.value = d.id1;
                    el.lbl2.textContent = 'NISN'; el.id2.value = d.id2;
                    el.lbl3.textContent = 'Kelas'; el.id3.value = d.id3;
                    el.hNamaSiswa.value = d.nama; el.hNipd.value = d.id1; el.hNisn.value = d.id2; el.hKelas.value = d.id3; el.hNis.value = d.id1;
                } else {
                    el.lbl1.textContent = 'NIP'; el.id1.value = d.id1;
                    el.lbl2.textContent = 'Pangkat/Gol'; el.id2.value = d.id2;
                    el.lbl3.textContent = 'Jabatan'; el.id3.value = d.id3;
                    el.hNamaSiswa.value = d.nama; el.hNis.value = d.id1; el.hNisn.value = ''; el.hNipd.value = ''; el.hKelas.value = '';
                }
            }

            function isSuratKhusus(jenis) {
                if (!jenis) return false;
                if (jenis === 'Surat Undangan' || jenis === 'Surat Panggilan Orang Tua/Wali' || jenis.indexOf('Surat Panggilan') !== -1) return true;
                if (specialLetterTypes.indexOf(jenis) !== -1) return true;
                for (var i = 0; i < specialLetterTypes.length; i++) {
                    if (jenis.indexOf(specialLetterTypes[i]) !== -1) return true;
                }
                return false;
            }

            function updateSigners() {
                el.penandatangan.innerHTML = '<option value="">-- Pilih Nama --</option>';
                var list = [];

                if (el.jabatan.value === 'Kepala Sekolah') {
                    list = kepseks;
                } else if (el.jabatan.value === 'Guru BK') {
                    list = guruBks;
                } else if (el.jabatan.value === 'Wali Kelas') {
                    var subject = getSubjectData();
                    if (subject.tipe === 'siswa' && subject.waliKelasId) {
                        list = teachers.filter(function (teacher) {
                            return String(teacher.id) === String(subject.waliKelasId);
                        });
                    }
                }

                for (var i = 0; i < list.length; i++) {
                    var t = list[i];
                    var opt = document.createElement('option');
                    opt.value = t.id || '';
                    opt.textContent = t.nama || '';
                    opt.dataset.nip = t.nip || '';
                    opt.dataset.pangkat = t.pangkat || '';
                    opt.dataset.jabatan = t.jabatan || '';
                    opt.dataset.unit = t.unit || namaSekolah;
                    el.penandatangan.appendChild(opt);
                }

                el.hNamaTtd.value = '';
                el.hNipTtd.value = '';
                el.hPangkatTtd.value = '';
                if (el.hPangkatTtdCompat) {
                    el.hPangkatTtdCompat.value = '';
                }
            }

            function getSignerData() {
                if (!el.penandatangan || el.penandatangan.selectedIndex < 1) {
                    return { id: '', nama: '', nip: '', pangkat: '', jabatan: el.jabatan.value || '', unit: namaSekolah };
                }
                var opt = el.penandatangan.options[el.penandatangan.selectedIndex];
                return {
                    id: opt.value || '',
                    nama: opt.textContent || '',
                    nip: opt.dataset.nip || '',
                    pangkat: opt.dataset.pangkat || '',
                    jabatan: el.jabatan.value || '',
                    unit: opt.dataset.unit || namaSekolah
                };
            }

            function updateSignerHidden() {
                var s = getSignerData();
                el.hNamaTtd.value = s.nama;
                el.hNipTtd.value = s.nip;
                el.hPangkatTtd.value = s.pangkat;
                if (el.hPangkatTtdCompat) {
                    el.hPangkatTtdCompat.value = s.pangkat;
                }
            }

            function blokSubjek() {
                var d = getSubjectData();
                if (!d.tipe) return '';

                if (d.tipe === 'siswa') {
                    return 'Nama        : ' + (d.nama || '-') + '\r\n' +
                           'NIPD        : ' + (d.id1 || '-') + '\r\n' +
                           'NISN        : ' + (d.id2 || '-') + '\r\n' +
                           'Kelas       : ' + (d.id3 || '-') + '\r\n\r\n';
                }

                return 'Nama        : ' + (d.nama || '-') + '\r\n' +
                       'NIP         : ' + (d.id1 || '-') + '\r\n' +
                       'Pangkat/Gol : ' + (d.id2 || '-') + '\r\n' +
                       'Jabatan     : ' + (d.id3 || '-') + '\r\n' +
                       'Unit Kerja  : ' + (d.unit || namaSekolah) + '\r\n\r\n';
            }

            function blokKegiatan() {
                var hari = el.hariKegiatan.value || hariIndonesia(el.tanggalKegiatan.value);
                var tanggal = tanggalIndonesia(el.tanggalKegiatan.value);
                var waktu = '';

                if (el.waktuMulai.value) {
                    waktu = el.waktuMulai.value;
                    if (el.waktuSelesai.value) {
                        waktu += ' s.d. ' + el.waktuSelesai.value;
                    }
                    waktu += ' WIB';
                }

                var tempat = el.tempat.value || '';
                var acara = el.acara.value || '';
                var text = '';

                if (hari || tanggal) {
                    text += 'Hari/Tanggal 	: ' + (hari || '-') + ', ' + (tanggal || '-') + '\r\n';
                }
                if (waktu) {
                    text += 'Waktu        	: ' + waktu + '\r\n';
                }
                if (tempat) {
                    text += 'Tempat       	: ' + tempat + '\r\n';
                }
                if (acara) {
                    text += 'Acara/Kegiatan	: ' + acara + '\r\n';
                }

                return text;
            }

            function formatKegiatanUndangan() {
                var hari = el.hariKegiatan.value || hariIndonesia(el.tanggalKegiatan.value);
                var tanggal = tanggalIndonesia(el.tanggalKegiatan.value);
                var waktu = el.waktuMulai.value || '';

                if (waktu && el.waktuSelesai.value) {
                    waktu += ' s.d. ' + el.waktuSelesai.value;
                }

                var tempat = el.tempat.value || '';
                var acara = el.acara.value || '';

                var text = '';
                
                text += 'Hari/Tanggal 		: ' + (hari ? hari + ', ' + (tanggal || '-') : (tanggal || '-')) + '\r\n';
                text += 'Pukul        		: ' + (waktu || '-') + ' WIB' + '\r\n';
                text += 'Tempat       		: ' + (tempat || '-') + '\r\n';
                text += 'Acara/Kegiatan        	: ' + (acara || '-') + '\r\n';

                return text;
            }

            function needsSignerBlock(jenis) {
                var tanpaSigner = [
                    'Surat Undangan',
                    'Surat Panggilan',
                    'Surat Edaran',
                    'Surat Permohonan',
                    'Surat Pemberitahuan',
                    'Surat Pengantar',
                    'Surat Teguran',
                    'Surat Peringatan'
                ];

                for (var i = 0; i < tanpaSigner.length; i++) {
                    if (jenis.indexOf(tanpaSigner[i]) !== -1) {
                        return false;
                    }
                }
                return true;
            }

            function generateSuratKhusus() {
                var jenis = el.jenisSurat.value;
                var signer = getSignerData();
                var text = '';

                if (needsSignerBlock(jenis)) {
                    text += jenis.toUpperCase() + '\r\nNomor: ' + (el.nomorSurat.value || '-') + '\r\n\r\n';
                    text += 'Yang bertanda tangan di bawah ini:\r\n\r\n' +
                           'Nama        : ' + (signer.nama || '-') + '\r\n' +
                           'NIP         : ' + (signer.nip || '-') + '\r\n' +
                           'Pangkat/Gol : ' + (signer.pangkat || '-') + '\r\n' +
                           'Jabatan     : ' + (signer.jabatan || '-') + '\r\n' +
                           'Unit Kerja  : ' + (signer.unit || namaSekolah) + '\r\n\r\n';

                    if (jenis.indexOf('Keterangan') !== -1) {
                        text += 'Dengan ini menerangkan bahwa:\r\n\r\n' +
                               blokSubjek() +
                               '\r\nAdalah benar murid dari ' + namaSekolah + '.\r\n\r\n' +
                               'Demikian Surat Keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya.\r\n';
                    } else if (jenis.indexOf('Pernyataan') !== -1) {
                        text += 'Dengan ini menyatakan bahwa:\r\n\r\n' +
                               blokSubjek() +
                               '\r\nAdalah benar murid dari ' + namaSekolah + '.\r\n\r\n' +
                               '\r\nDemikian Surat Pernyataan ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya.\r\n';
                    } else if (jenis === 'Surat Tugas') {
                        text += 'Memberikan tugas kepada:\r\n\r\n' +
                               blokSubjek() +
                               '\r\nUntuk mengikuti acara/kegiatan pada:\r\n\r\n' +
                               blokKegiatan() +
                               '\r\nDemikian surat tugas ini dibuat untuk dilaksanakan dengan penuh tanggung jawab.\r\n';
                    } else {
                        text += 'Dengan ini disampaikan hal-hal sebagai berikut.\r\n\r\n' +
                               blokSubjek() +
                               blokKegiatan() +
                               'Demikian surat ini dibuat.\r\n';
                    }
                } else {
                    if (jenis === 'Surat Panggilan' || jenis === 'Surat Panggilan Orang Tua/Wali' || jenis.indexOf('Surat Panggilan') !== -1) {
                        text += 'Dengan hormat,\r\n\r\n' +
                                'Sehubungan dengan adanya hal-hal yang perlu disampaikan oleh pihak sekolah kepada Bapak/Ibu selaku Orang Tua/Wali yang bersangkutan, maka kami sangat mengharapkan kehadiran Bapak/Ibu pada:\r\n\r\n' +
                                formatKegiatanUndangan() + '\r\n' +
                                'Demikian Surat Panggilan ini kami sampaikan. Atas kehadirannya kami ucapkan terima kasih.\r\n';
                    } else if (jenis === 'Surat Undangan' || jenis.indexOf('Surat Undangan') !== -1) {
                        text += 'Dengan hormat,\r\n\r\n' +
                                'Bersama surat ini kami mengundang Bapak/Ibu/Sdr/i untuk dapat menghadiri acara/kegiatan sekolah yang diselenggarakan pada:\r\n\r\n' +
                                formatKegiatanUndangan() + '\r\n' +
                                'Demikian Surat Undangan ini kami sampaikan. Atas kehadirannya kami ucapkan terima kasih.\r\n';
                    } else {
                        text += 'Dengan hormat,\r\n\r\n' +
                                'Sehubungan dengan hal tersebut, bersama ini kami sampaikan informasi:\r\n\r\n' +
                                blokSubjek() +
                                blokKegiatan() +
                                '\r\nDemikian surat ini kami sampaikan. Atas perhatian dan kerja sama yang baik, kami ucapkan terima kasih.\r\n';
                    }
                }

                return text;
            }

            function generateSuratUmum() {
                return 'Dengan hormat,\r\n\r\n' +
                       'Sehubungan dengan hal tersebut, bersama ini kami sampaikan informasi sebagai berikut:\r\n\r\n' +
                       blokSubjek() +
                       blokKegiatan() +
                       '\r\nDemikian surat ini kami sampaikan. Atas perhatian dan kerja sama yang baik, kami ucapkan terima kasih.';
            }

            function generateIsiSurat() {
                if (!el.jenisSurat.value) {
                    alert('Pilih jenis surat terlebih dahulu!');
                    return;
                }

                var hasil = isSuratKhusus(el.jenisSurat.value) ? generateSuratKhusus() : generateSuratUmum();
                el.isiSurat.value = typeof hasil === 'string' ? hasil : '';
            }

            el.penerima.addEventListener('change', function () {
                updateSubject();
                if (el.jabatan.value === 'Wali Kelas') {
                    updateSigners();
                }
            });

            el.tanggalKegiatan.addEventListener('change', function () {
                el.hariKegiatan.value = hariIndonesia(el.tanggalKegiatan.value);
            });

            el.jabatan.addEventListener('change', function () {
                updateSigners();
            });

            el.penandatangan.addEventListener('change', function () {
                updateSignerHidden();
            });


            var suratForm = $('suratForm');
            if (suratForm) {
                suratForm.addEventListener('submit', function () {
                    updateSubject();
                    updateSignerHidden();
                });
            }

            $('btnGenerate').addEventListener('click', function () {
                generateIsiSurat();
            });

            $('btnClear').addEventListener('click', function () {
                if (confirm('Kosongkan isi surat?')) {
                    el.isiSurat.value = '';
                }
            });

            function tanggalHariIni() {
                var now = new Date();
                var y = now.getFullYear();
                var m = String(now.getMonth() + 1).padStart(2, '0');
                var d = String(now.getDate()).padStart(2, '0');
                return y + '-' + m + '-' + d;
            }

            var hariIni = tanggalHariIni();

            if (!el.tanggalSurat.value) el.tanggalSurat.value = hariIni;
            if (!el.tanggalKegiatan.value) el.tanggalKegiatan.value = hariIni;
            if (!el.waktuMulai.value) el.waktuMulai.value = '08:00';
            if (!el.waktuSelesai.value) el.waktuSelesai.value = '12:00';
            if (!el.hariKegiatan.value) el.hariKegiatan.value = hariIndonesia(hariIni);

            updateSubject();
            updateSigners();

            var oldJabatan = @json(old('penandatangan_jabatan', ''));
            var oldPenandatanganId = @json(old('penandatangan_id', ''));
            var oldPenerimaId = @json(old('penerima_id', ''));

            if (oldPenerimaId) {
                el.penerima.value = oldPenerimaId;
                updateSubject();
            }

            if (oldJabatan) {
                el.jabatan.value = oldJabatan;
                updateSigners();
            }

            if (oldPenandatanganId) {
                setTimeout(function () {
                    el.penandatangan.value = oldPenandatanganId;
                    updateSignerHidden();
                }, 100);
            }
        });
    </script>
    @endpush

</x-app-layout>