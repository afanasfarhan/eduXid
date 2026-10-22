<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pengaturan &amp; Sinkronisasi Sistem
        </h2>
    </x-slot>

    @php
        $user = auth()->user();
        $userRole = $user?->roleName();
        $canSendTeacherWork = $user?->canTeach() ?? false;

        /*
         * [CLOUD-UI] Guard route — view tetap render walau route
         * Cloud belum terdaftar (pola Route::has, sama seperti
         * dashboard & LMS).
         */
        $cloudPullReady = Route::has('guru.pullMasterDataCloud');
        $cloudWorkReady = Route::has('guru.pushWorkProductsCloud');
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    {{-- [XSS-FIX] Pesan flash adalah teks polos — escape, jangan raw. --}}
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('warning'))
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-800 px-4 py-3 rounded mb-6">
                    {{ session('warning') }}
                </div>
            @endif

            {{-- ================================================= --}}
            {{-- 1. CLOUD — paling atas                            --}}
            {{-- Fitur #2 (master via Cloud) & #3 (seluruh hasil   --}}
            {{-- pekerjaan via Cloud). Route di-guard Route::has.  --}}
            {{-- ================================================= --}}
            @if($cloudPullReady || $cloudWorkReady)
            <div class="eduxid-settings-section mb-10">

                <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                    ☁️ Sinkronisasi via eduXid Cloud (Mode Online)
                </h3>

                <p class="text-sm text-gray-600 mb-6">
                    Jalur utama melalui internet — tidak perlu satu jaringan
                    dengan komputer Admin.
                </p>

                @if(isset($cloudPaired) && $cloudPaired === false)
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800 mb-6">
                        @if($userRole === 'tendik')
                            ⚠️ Akun Tendik ini belum memiliki pairing Cloud aktif untuk identitasnya sendiri.
                            Hubungi Admin Sekolah untuk memeriksa aktivasi akun GTK dan kode pairing yang sesuai.
                            Jangan menggunakan kode atau perangkat guru lain.
                        @else
                            ⚠️ Akun GTK ini belum memiliki pairing Cloud aktif untuk identitasnya sendiri.
                            Periksa status pada <a href="{{ route('dashboard') }}" class="font-bold underline">Dashboard</a>
                            dan gunakan Kode GTK yang disiapkan Admin Sekolah.
                        @endif
                    </div>
                @endif

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                    @if($cloudWorkReady && $canSendTeacherWork)
                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-emerald-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">☁️</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Kirim Hasil Pekerjaan via Cloud</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Kirim <b>seluruh hasil pekerjaan</b> via Cloud: nilai akademik, sikap,
                            catatan BK, catatan wali, rekap absensi rapor, nilai ekskul, dan
                            kokurikuler. Data guru diteruskan ke <b>Admin</b>; khusus
                            <b>Nilai Akhir Rapor</b> juga diarahkan ke <b>Wali Kelas</b>
                            berdasarkan rombel penugasan.

                            <br><br>

                            <span class="text-gray-400 text-xs">
                                Arsip surat &amp; bank soal dikirim via LAN/flashdisk
                                (menu biru di bagian bawah halaman ini).
                            </span>
                        </p>

                        <form action="{{ route('guru.pushWorkProductsCloud') }}" method="POST" class="mt-auto">
                            @csrf

                            <button
                                type="submit"
                                class="eduxid-btn w-full bg-emerald-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors text-sm font-bold"
                                onclick="return confirm('Kirim seluruh hasil pekerjaan via Cloud ke Admin? Nilai Akhir Rapor juga akan diteruskan ke Wali Kelas terkait.')"
                            >
                                Kirim via Cloud
                            </button>
                        </form>

                    </div>
                    @endif

                    @if($cloudPullReady)
                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-cyan-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">⬇️</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Tarik Data Pokok via Cloud</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Ambil Data Pokok dari Cloud eduXid — tanpa perlu terhubung
                            ke jaringan komputer Admin. Wajib: laptop sudah melakukan pairing.

                            <br><br>

                            <span class="text-green-600 font-bold text-xs">
                                ℹ️ Data nilai, absensi, dan bank soal yang sudah
                                Anda input tidak akan terhapus.
                            </span>
                        </p>

                        <form action="{{ route('guru.pullMasterDataCloud') }}" method="POST" class="mt-auto">
                            @csrf

                            <button
                                type="submit"
                                class="eduxid-btn w-full bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors text-sm font-bold"
                                onclick="return confirm('Tarik Data Pokok via Cloud? Data master diperbarui; data nilai Anda tetap aman.')"
                            >
                                Tarik via Cloud
                            </button>
                        </form>

                    </div>
                    @endif

                </div>
            </div>
            @endif

            {{-- ================================================= --}}
            {{-- 2. LAN — di tengah                                --}}
            {{-- ================================================= --}}
            <div class="eduxid-settings-section mb-10">

                <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                    📡 Sinkronisasi Jaringan Lokal (Mode LAN)
                </h3>

                <p class="text-sm text-gray-600 mb-6">
                    Hubungkan laptop Anda dengan komputer Admin (Server)
                    melalui jaringan lokal yang sama untuk mengirim dan
                    menerima data secara langsung tanpa flashdisk.
                </p>

                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-red-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">⚙️</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Koneksi Server</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Masukkan Alamat IP komputer Admin (Server).
                        </p>

                        <form action="{{ route('guru.updateServerIp') }}" method="POST">
                            @csrf

                            <div class="flex flex-col gap-2">
                                <input
                                    type="text"
                                    name="server_ip"
                                    value="{{ old('server_ip', $currentServerIp ?? '') }}"
                                    placeholder="Contoh: 192.168.1.100"
                                    inputmode="decimal"
                                    pattern="^(\d{1,3}\.){3}\d{1,3}$"
                                    title="Format IPv4, contoh: 192.168.1.100"
                                    class="border-gray-300 rounded-md shadow-sm p-2 text-sm w-full text-center font-bold"
                                    required
                                >
                                <button type="submit" class="eduxid-btn bg-red-600 text-white px-4 py-2 rounded-md text-sm font-bold hover:bg-red-700 transition-colors">
                                    Simpan IP Server
                                </button>
                            </div>
                        </form>

                    </div>

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-green-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">⬇️</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Tarik Data Pokok</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Ambil Data Pokok dari komputer Admin (Server)
                            melalui jaringan lokal. Lakukan ini pada saat awal semester.

                            <br><br>

                            <span class="text-green-600 font-bold text-xs">
                                ℹ️ Data nilai, absensi, dan bank soal yang sudah
                                Anda input tidak akan terhapus.
                            </span>
                        </p>

                        <form action="{{ route('guru.pullMasterDataApi') }}" method="POST" class="mt-auto">
                            @csrf

                            <button
                                type="submit"
                                class="eduxid-btn w-full bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors text-sm font-bold"
                                onclick="return confirm('Tarik Data Pokok dari Server? Data master (murid, kelas, mapel) akan diperbarui, namun data nilai Anda akan tetap aman.')"
                            >
                                Tarik Data Pokok
                            </button>
                        </form>

                    </div>

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-blue-100 border-t-4 border-blue-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">⬆️</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Kirim Hasil Pekerjaan</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            @if($userRole === 'tendik')
                                Kirim arsip Surat yang Anda buat ke komputer
                                Admin (Server) untuk dicadangkan.
                            @else
                                Kirim seluruh hasil pekerjaan Anda
                                (Nilai, Absensi, Bank Soal) ke komputer Admin
                                untuk mencetak rapor murid.
                            @endif
                        </p>

                        <form action="{{ route('guru.pushGradesApi') }}" method="POST" class="mt-auto">
                            @csrf

                            <button
                                type="submit"
                                class="eduxid-btn w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 transition-colors text-sm font-bold"
                                onclick="return confirm('Kirim seluruh data pekerjaan ke Server Admin?')"
                            >
                                Kirim Data
                            </button>
                        </form>

                    </div>

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">🔄</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Update / Patch</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Periksa versi aplikasi dan pasang pembaruan
                            eduXid (patch) secara aman dari Server Admin.
                        </p>

                        <a
                            href="{{ route('guru.update.system') }}"
                            class="eduxid-btn block w-full text-center bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition-colors text-sm font-bold mt-auto"
                        >
                            Cek &amp; Pasang Update
                        </a>

                    </div>

                </div>
            </div>

            {{-- ================================================= --}}
            {{-- 3. OFFLINE — paling bawah                         --}}
            {{-- ================================================= --}}
            <div class="eduxid-settings-section mb-10">

                <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                    💾 Sinkronisasi Manual (Mode Offline)
                </h3>

                <p class="text-sm text-gray-600 mb-6">
                    Gunakan fitur ini jika internet dan jaringan lokal Anda bermasalah.
                    Anda bisa mengimpor/ekspor data menggunakan file
                    <code>.json</code> dan flashdisk.
                </p>

                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-amber-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">📂</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Impor Data Pokok</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Unggah file Data Pokok <code>(m2g_*.json)</code>
                            yang diberikan oleh Admin eduXid.

                            <br><br>

                            <span class="text-green-600 font-bold text-xs">
                                ℹ️ Data nilai, absensi, dan bank soal yang sudah
                                Anda input tidak akan terhapus.
                            </span>
                        </p>

                        <form action="{{ route('guru.imporMaster') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                            @csrf

                            <div class="mb-3">
                                <input
                                    type="file"
                                    name="json_file"
                                    accept=".json,.txt"
                                    required
                                    class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700"
                                >
                            </div>

                            <button type="submit" class="eduxid-btn w-full bg-red-500 text-white py-2 rounded-md hover:bg-red-600 transition-colors text-sm font-bold">
                                Impor Data Pokok
                            </button>
                        </form>

                    </div>

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-green-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">💾</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Backup Hasil Pekerjaan</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Unduh file <code>.json</code> berisi hasil pekerjaan Anda.

                            @if($userRole === 'tendik')
                                Arsip Surat yang Anda buat akan ikut tersimpan di sini.
                            @else
                                Nilai, Absensi, Sikap, dan Bank Soal Anda
                                akan tersimpan di sini.
                            @endif
                        </p>

                        <div class="mt-auto">
                            <a href="{{ route('guru.ekspor') }}" class="eduxid-btn block text-center bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors text-sm font-bold">
                                Backup Data
                            </a>
                        </div>

                    </div>

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-yellow-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">♻️</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Restore Hasil Pekerjaan</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Pulihkan hasil pekerjaan Anda dari file <code>.json</code>
                            yang telah di-backup sebelumnya.

                            <br>

                            <span class="text-red-500 font-bold text-xs">
                                Peringatan: Data saat ini akan ditimpa!
                            </span>
                        </p>

                        <form action="{{ route('guru.imporTransaksi') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                            @csrf

                            <div class="mb-3">
                                <input
                                    type="file"
                                    name="json_file"
                                    accept=".json,.txt"
                                    required
                                    class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700"
                                >
                            </div>

                            <button
                                type="submit"
                                class="eduxid-btn w-full bg-yellow-500 text-white py-2 rounded-md hover:bg-yellow-600 transition-colors text-sm font-bold"
                                onclick="return confirm('Apakah Anda yakin? Data pekerjaan saat ini akan ditimpa!')"
                            >
                                Restore Data
                            </button>
                        </form>

                    </div>

                    <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-purple-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">

                        <div class="text-3xl mb-2">📷</div>

                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Upload Foto Murid</h4>

                        <p class="text-sm text-gray-500 mb-4 flex-grow">
                            Unggah file <code>.zip</code> berisi foto murid.
                            Nama file diawali <strong>NISN</strong>, termasuk yang berawalan <strong>0</strong>.
                            Contoh: <code>0123456789.jpg</code> atau <code>0123456789 - Nama.png</code>.
                            ZIP boleh memuat subfolder.
                        </p>

                        <form action="{{ route('guru.uploadFotoZip') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                            @csrf

                            <div class="mb-3">
                                <input
                                    type="file"
                                    name="zip_file"
                                    accept=".zip"
                                    required
                                    class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-gray-700"
                                >
                            </div>

                            <button type="submit" class="eduxid-btn w-full bg-purple-600 text-white py-2 rounded-md hover:bg-purple-700 transition-colors text-sm font-bold">
                                Upload Foto Murid
                            </button>
                        </form>
                        @if(session('foto_zip_report'))
                            @php($fotoReport = session('foto_zip_report'))
                            <details class="mt-3 text-xs text-gray-700" @if(($fotoReport['skipped'] ?? 0) > 0) open @endif>
                                <summary class="cursor-pointer font-semibold">Rincian ZIP: {{ (int) ($fotoReport['saved'] ?? 0) }} berhasil, {{ (int) ($fotoReport['skipped'] ?? 0) }} gagal/dilewati, {{ (int) ($fotoReport['ignored'] ?? 0) }} file sistem diabaikan</summary>
                            @if(!empty($fotoReport['reasons']))
                                <div class="mt-2 space-y-1 font-semibold">
                                    @foreach($fotoReport['reasons'] as $alasan => $jumlah)
                                        <p>{{ (int) $jumlah }} file — {{ $alasan }}</p>
                                    @endforeach
                                </div>
                            @endif
                                <ul class="mt-2 list-disc pl-4 space-y-1">
                                    @foreach(($fotoReport['failures'] ?? []) as $failure)
                                        <li>{{ $failure['file'] ?? 'File' }} — {{ $failure['reason'] ?? 'Tidak diproses' }}</li>
                                    @endforeach
                                </ul>
                                @if(($fotoReport['more_failures'] ?? 0) > 0)
                                    <p class="mt-1">{{ (int) $fotoReport['more_failures'] }} alasan lain tidak ditampilkan.</p>
                                @endif
                            </details>
                        @endif

                    </div>

                </div>
            </div>

        </div>
    </div>
</x-app-layout>
