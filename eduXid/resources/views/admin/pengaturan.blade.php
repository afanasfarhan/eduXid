<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Pengaturan Sistem</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ session('error') }}</div>
            @endif

            @if (session('one_time_password'))
                <section class="bg-amber-50 border-2 border-amber-500 rounded-xl px-5 py-4 mb-6" aria-label="Password sementara satu kali">
                    <h3 class="font-bold text-amber-900">Password Sementara — Ditampilkan Sekali</h3>
                    <p class="text-sm text-amber-900 mt-1">Akun: {{ session('one_time_account') }}. Salin password ini sekarang dan serahkan langsung kepada pemilik akun. Password tidak dapat ditampilkan kembali setelah halaman dimuat ulang.</p>
                    <input type="password" id="eduxid-one-time-pass" value="{{ session('one_time_password') }}" readonly
                           autocomplete="off" spellcheck="false" aria-label="Password sementara"
                           class="w-full max-w-md mt-3 px-3 py-2 rounded-md font-mono border-amber-400" />
                    <button type="button" class="ml-2 mt-2 px-3 py-2 rounded-md bg-amber-700 text-white font-bold"
                            onclick="const p=document.getElementById('eduxid-one-time-pass');p.type=p.type==='password'?'text':'password';this.textContent=p.type==='password'?'Tampilkan':'Sembunyikan';">Tampilkan</button>
                    <p class="text-xs text-amber-800 mt-2">Reset ini hanya untuk login lokal Laravel, tidak mengubah password PWA Cloud. Jangan kirim password melalui grup atau log.</p>
                </section>
            @endif
            @if ($errors->has('current_password'))
                <div class="bg-red-50 border border-red-400 text-red-800 px-4 py-3 rounded mb-5">{{ $errors->first('current_password') }}</div>
            @endif


            @if(isset($pendingActivations) && $pendingActivations->isNotEmpty())
            <section class="bg-white shadow rounded-xl border border-blue-200 p-5 mb-6" aria-label="Aktivasi akun impor Excel">
                <h3 class="font-bold text-gray-800">Aktivasi Akun Baru (Impor Excel)</h3>
                <p class="text-sm text-gray-600 my-2">Password awal setiap akun dibuat acak. Admin hanya dapat mengambil satu kali dalam 7 hari, setelah memasukkan ulang password Admin. Jangan kirim melalui grup atau tautan publik.</p>
                <form method="POST" action="{{ route('admin.ambilAktivasi') }}" class="flex flex-wrap gap-3 items-end" autocomplete="off">
                    @csrf
                    <div class="flex-1 min-w-48">
                        <label for="initial-credential-user" class="block text-sm font-semibold">Akun yang akan diaktivasi</label>
                        <select id="initial-credential-user" name="user_id" required class="w-full rounded border-gray-300">
                            <option value="">Pilih akun</option>
                            @foreach($pendingActivations as $account)
                                <option value="{{ $account->user_id }}">{{ $account->name }} ({{ $account->role }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex-1 min-w-48">
                        <label for="activation-admin-pass" class="block text-sm font-semibold">Password Admin saat ini</label>
                        <input id="activation-admin-pass" type="password" name="current_password" required autocomplete="current-password" class="w-full rounded border-gray-300">
                    </div>
                    <button type="submit" onclick="return confirm('Kredensial hanya dapat ditampilkan sekali. Lanjutkan?')" class="eduxid-btn bg-blue-600 text-white rounded-md px-4 py-2 font-bold">Ambil Password Aktivasi</button>
                </form>
            </section>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <h4 class="font-bold text-gray-800 text-lg">Siapkan Paket GTK</h4>
                    <p class="text-sm text-gray-600 my-4">Siapkan aplikasi, Data Pokok, dan pairing PC setiap GTK sebelum dibagikan. GTK cukup mengekstrak paketnya dan login.</p>
                    <a href="{{ Route::has('admin.gtk-bundles.index') ? route('admin.gtk-bundles.index') : route('admin.pengaturan') }}" class="eduxid-btn block text-center bg-indigo-600 text-white py-2 rounded-md font-bold">Buka Persiapan Paket GTK</a>
                </div>

                {{-- Kartu EDUXID CLOUD (pintu utama) --}}
                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">☁️</span>
                        <h4 class="font-bold text-gray-800 text-lg">eduXid Cloud</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">
                        Pusat kendali sinkronisasi: Buat <b>Kode Guru</b> untuk LMS Guru, dan
                        <b>Sinkronkan Direktori Akun Siswa</b> (NISN, nama, rombel, nama sekolah) ke Cloud.
                    </p>
                    <a href="{{ route('admin.eduxid-cloud') }}"
                       class="eduxid-btn block w-full text-center bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 text-sm font-bold mt-auto">
                        ☁️ Buka Halaman eduXid Cloud
                    </a>
                </div>


                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-green-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">💾</span>
                        <h4 class="font-bold text-gray-800 text-lg">Backup Database</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Unduh cadangan seluruh data eduXid, termasuk data semua guru yang sudah dikirim ke eduXid Admin (Server).</p>
                    <a href="{{ route('admin.backup.create') }}" class="eduxid-btn block text-center bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 text-sm font-bold mt-auto">
                        Download Database
                    </a>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-yellow-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">♻️</span>
                        <h4 class="font-bold text-gray-800 text-lg">Restore Database</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Unggah data Admin (Server) hasil backup untuk mengembalikan data. <span class="text-red-500 font-bold block mt-1">Peringatan: Data saat ini akan ditimpa!</span></p>
                    <form action="{{ route('admin.backup.restore') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                        @csrf
                        <div class="mb-3">
                            <input type="file" name="sql_file" accept=".sqlite,.txt,.sql" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2">
                        </div>
                        <button type="submit" class="eduxid-btn w-full bg-yellow-500 text-white py-2 rounded-md hover:bg-yellow-600 text-sm font-bold" onclick="return confirm('Apakah Anda yakin? Data saat ini akan ditimpa!')">
                            Restore Database
                        </button>
                    </form>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">🔑</span>
                        <h4 class="font-bold text-gray-800 text-lg">Reset Login Akun</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Atur ulang password akun lokal GTK/Siswa menjadi password sementara acak. Admin wajib memverifikasi passwordnya, lalu memberikan password sementara secara pribadi. Tidak mengubah password PWA Cloud.</p>
                    <form action="{{ route('admin.resetPassword') }}" method="POST" class="mt-auto">
                        @csrf
                        <div class="mb-3">
                            <select name="user_id" class="w-full border-gray-300 rounded-md shadow-sm text-sm p-2" required>
                                <option value="">-- Pilih Akun --</option>
                                @foreach ($users as $u)
                                    @php
                                        $formattedName = mb_convert_case(strtolower($u->name), MB_CASE_TITLE, 'UTF-8');
                                        if (!empty($u->gelar_depan)) $formattedName = $u->gelar_depan . ' ' . $formattedName;
                                        if (!empty($u->gelar_belakang)) $formattedName = $formattedName . ', ' . $u->gelar_belakang;
                                    @endphp
                                    <option value="{{ $u->id }}">{{ $formattedName }} ({{ $u->login_id }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="eduxid-reset-admin-password" class="text-sm font-medium">Konfirmasi Password Admin</label>
                            <input id="eduxid-reset-admin-password" type="password" name="current_password" required autocomplete="current-password"
                                class="w-full border-gray-300 rounded-md shadow-sm text-sm p-2 mt-1" placeholder="Masukkan password Admin saat ini">
                        </div>
                        <button type="submit" class="eduxid-btn w-full bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 text-sm font-bold" onclick="return confirm('Reset password lokal akun terpilih menjadi password sementara acak? Pastikan Anda dapat menyerahkannya kepada pemilik akun.')">
                            Reset Password
                        </button>
                    </form>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-purple-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">📷</span>
                        <h4 class="font-bold text-gray-800 text-lg">Upload Foto Siswa</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Unggah ZIP foto siswa (JPG, PNG, WebP). Nama file diawali NISN, misalnya <code>0123456789.jpg</code> atau <code>0123456789 - Nama.jpg</code>. NISN yang dimulai angka <strong>0</strong> tetap dikenali. ZIP boleh berisi subfolder.</p>

                    <form action="{{ route('admin.uploadFotoZip') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                        @csrf
                        <div class="mb-3">
                            <input type="file" name="zip_file" accept=".zip" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2">
                        </div>
                        <button type="submit" class="eduxid-btn w-full bg-purple-600 text-white py-2 rounded-md hover:bg-purple-700 text-sm font-bold">
                            Upload Foto Siswa
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

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-blue-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">📤</span>
                        <h4 class="font-bold text-gray-800 text-lg">Download Data Guru</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Unduh file <code>backup_guru_*.json</code> berisi pekerjaan Guru (Bank Soal, Nilai, CBT) untuk diberikan kepada Guru agar bisa di restore di perangkat lokalnya.</p>

                    <form action="{{ route('admin.eksporBackupGuru') }}" method="POST" class="mt-auto">
                        @csrf
                        <div class="mb-3">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Pilih Guru</label>
                            <select name="teacher_id" class="w-full border-gray-300 rounded-md shadow-sm text-sm p-2" required>
                                <option value="">-- Pilih Guru --</option>
                                @foreach ($gurus as $g)
                                    <option value="{{ $g->id }}">
                                        {{ $g->gelar_depan ? $g->gelar_depan . ' ' : '' }}{{ mb_convert_case(strtolower($g->nama), MB_CASE_TITLE, 'UTF-8') }}{{ $g->gelar_belakang ? ', ' . $g->gelar_belakang : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="eduxid-btn w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 text-sm font-bold">
                            Download Data Guru
                        </button>
                    </form>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-red-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">📥</span>
                        <h4 class="font-bold text-gray-800 text-lg">Upload Data Guru</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Unggah file <code>backup_guru_*.json</code> dari Guru untuk memulihkan pekerjaannya ke Server Admin. <span class="text-red-500 font-bold block mt-1">Data Guru lama di server akan ditimpa!</span></p>

                    <form action="{{ route('admin.imporBackupGuru') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                        @csrf
                        <div class="mb-3">
                            <input type="file" name="json_file" accept=".json,.txt" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2">
                        </div>
                        <button type="submit" class="eduxid-btn w-full bg-red-500 text-white py-2 rounded-md hover:bg-red-600 text-sm font-bold" onclick="return confirm('Apakah Anda yakin? Data pekerjaan Guru di server akan ditimpa dengan file ini!')">
                            Upload Data Guru
                        </button>
                    </form>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-gray-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">🧹</span>
                        <h4 class="font-bold text-gray-800 text-lg">Optimasi Sistem</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">Bersihkan file sampah hasil backup/restore yang gagal, cache yang menumpuk, dan file log yang membengkak untuk menghemat penyimpanan komputer.</p>

                    <form action="{{ route('admin.cleanUpSystem') }}" method="POST" class="mt-auto">
                        @csrf
                        <button type="submit" class="eduxid-btn w-full bg-gray-700 text-white py-2 rounded-md hover:bg-gray-800 text-sm font-bold" onclick="return confirm('Yakin ingin membersihkan file sampah sistem?')">
                            Bersihkan Sampah Sistem
                        </button>
                    </form>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-indigo-600 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">🔄</span>
                        <h4 class="font-bold text-gray-800 text-lg">Update / Patch eduXid</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">
                        Periksa versi aplikasi, lihat patch yang tersedia, dan pasang pembaruan
                        eduXid secara aman tanpa mengganggu data sekolah.
                    </p>
                    <a href="{{ route('admin.update') }}" class="eduxid-btn block w-full text-center bg-indigo-600 text-white py-2 rounded-md hover:bg-indigo-700 text-sm font-bold mt-auto">
                        Kelola Update / Patch
                    </a>
                </div>

                <div class="eduxid-settings-card bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-red-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                    <div class="flex items-center mb-3">
                        <span class="text-3xl mr-3">⚠️</span>
                        <h4 class="font-bold text-gray-800 text-lg">Reset Total Sistem</h4>
                    </div>
                    <p class="text-sm text-gray-500 mb-4 flex-grow">
                        Menghapus seluruh data sekolah (Siswa, GTK, Kelas, Nilai, Bank Soal, dll.) dan mengembalikan aplikasi ke kondisi awal.
                        <span class="text-red-500 font-bold block mt-1">Aksi ini tidak dapat dibatalkan!</span>
                    </p>

                    <form action="{{ route('admin.resetTotalData') }}" method="POST" class="mt-auto">
                        @csrf
                        <div class="mb-3">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Ketik untuk konfirmasi:</label>
                            <input type="text" name="konfirmasi" placeholder="HAPUS SEMUA DATA" required class="block w-full border border-red-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-3 text-center font-bold uppercase tracking-wider">
                        </div>
                        <button type="submit" class="eduxid-btn w-full bg-red-600 text-white py-2 rounded-md hover:bg-red-700 transition-colors text-sm font-bold" onclick="return confirm('APAKAH ANDA BENAR-BENAR YAKIN? Semua data akan hilang permanen dan tidak bisa dikembalikan!')">
                            Hapus Semua Data
                        </button>
                    </form>
                </div>

            </div>

        </div>
    </div>
</x-app-layout>