<x-app-layout>
    @php
        // Gunakan layanan versi yang juga membaca marker rilis hasil auto-update.
        // Tidak mengubah database atau status pemasangan.
        $appVersion = app(\App\Services\Update\CurrentVersion::class)->get();

        $user = auth()->user();
        $role = $user->roleName();
        $isTeacherDashboard = $user->isGuru();
        $canUseUjian = $user->canTeach();
        $broadcastInbox = app(\App\Services\Broadcast\BroadcastInboxService::class);
        $canUseBroadcast = $broadcastInbox->allowed($user);
        $dashboardBroadcastMessages = $canUseBroadcast ? $broadcastInbox->cached($user, 3, false) : collect();
        $dashboardBroadcastCount = $canUseBroadcast ? $broadcastInbox->unreadCount($user) : 0;

        // Setup tetap mengikuti DashboardController; akses mengajar mengikuti User.
        $isSetup = ($isTeacherDashboard || $role === 'tendik')
            && (bool) ($stats['setup_mode'] ?? false);
    @endphp

    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ $role == 'admin' ? 'Dasbor Admin' : ($role == 'tendik' ? 'Dasbor Tendik' : ($isTeacherDashboard ? 'Dasbor Guru' : 'Dasbor GTK')) }}
                </h2>
                <span class="text-xs font-bold text-indigo-600 bg-indigo-100 px-2 py-1 rounded-full border border-indigo-200">
                    v{{ $appVersion }}
                </span>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if($canUseBroadcast)
                {{-- Banner di paling atas Dasbor; data cache offline tampil langsung. --}}
                <div id="dashboard-broadcast-banner" aria-live="polite">
                    @include('broadcast.partials.dashboard-banner', ['messages' => $dashboardBroadcastMessages, 'unreadCount' => $dashboardBroadcastCount])
                </div>
                <p id="dashboard-broadcast-status" role="status" aria-live="polite" class="mb-5 text-xs text-gray-600">
                    {{ $broadcastStatus['message'] ?? 'Memeriksa kotak masuk melalui eduXid Cloud…' }}
                </p>
            @endif

            {{-- BANNER STATUS JARINGAN --}}
            @if(($stats['is_online'] ?? false))
                <div class="mb-6 bg-green-100 border-l-4 border-green-500 text-green-700 px-4 py-3 rounded flex items-center gap-2 shadow-sm">
                    <span class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></span>
                    <strong>Mode Online:</strong> Terhubung ke jaringan lokal.
                </div>
            @else
                <div class="mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 rounded flex items-center gap-2 shadow-sm">
                    <span class="w-3 h-3 bg-red-500 rounded-full"></span>
                    <strong>Mode Offline:</strong> Anda bekerja menggunakan data lokal.
                </div>
            @endif

            @if(session('success'))
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">{{ session('error') }}</div>
            @endif

            {{-- ========================================================= --}}
            {{-- [SETUP] GURU / WALI / BK / TENDIK - TAMPILAN 100% IDENTIK --}}
            {{-- ========================================================= --}}
            @if($isSetup)

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6 transition-all duration-300 hover:shadow-lg">
                    <div class="p-6 text-gray-900 text-center">
                        <h1 class="text-2xl font-bold text-indigo-600">Selamat Datang di eduXid!</h1>
                        <p class="text-sm text-gray-500 mt-2">Akun Anda belum memiliki data. Silakan lakukan sinkronisasi Data Pokok terlebih dahulu. Hubungi Admin/Operator Sekolah.</p>
                    </div>
                </div>

                {{-- [CLOUD-PAIRING] Kartu pairing Cloud --}}
                <div class="bg-white p-6 rounded-xl shadow-md border-l-4 mb-6 transition-all duration-300 hover:shadow-lg
                    {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? 'border-green-500' : 'border-yellow-500' }}">

                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">

                        <div class="flex items-start gap-4">
                            <div class="text-4xl">
                                {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? '☁️' : '⚠️' }}
                            </div>

                            <div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">
                                    eduXid Cloud
                                    <span class="ml-2 text-xs font-bold px-2 py-1 rounded-full align-middle
                                        {{ ($stats['cloud_pairing']['is_paired'] ?? false)
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-yellow-100 text-yellow-700' }}">
                                        {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? 'TERHUBUNG' : 'BELUM TERHUBUNG' }}
                                    </span>
                                </h3>

                                @if ($stats['cloud_pairing']['is_paired'] ?? false)
                                    <p class="text-sm text-gray-500">
                                        Laptop ini terhubung ke eduXid Cloud.
                                        {{ auth()->user()?->roleName() === 'tendik' ? 'Perangkat GTK ini memiliki pairing Cloud aktif.' : 'Sinkronisasi konten dan nilai ke perangkat murid aktif.' }}
                                    </p>
                                @else
                                    <p class="text-sm text-gray-500">
                                        {{ auth()->user()?->roleName() === 'tendik' ? 'Pairing akun Tendik belum terkonfirmasi.' : 'Sinkronisasi konten & nilai ke perangkat murid belum aktif.' }}
                                        Masukkan <b>{{ auth()->user()?->roleName() === 'tendik' ? 'Kode GTK Tendik' : 'Kode Guru' }}</b> dari Admin Sekolah.
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if (!($stats['cloud_pairing']['is_paired'] ?? false))
                            <div class="shrink-0 w-full sm:w-auto">
                                <form id="cloud-pair-form" onsubmit="return false;" class="flex flex-col sm:flex-row gap-2">
                                    @csrf
                                    <input type="text" id="cloud-pair-code" name="enrollment_token"
                                           placeholder="{{ auth()->user()?->roleName() === 'tendik' ? 'Tempel Kode GTK Tendik dari Admin' : 'Tempel Kode Guru dari Admin' }}"
                                           autocomplete="off"
                                           class="w-full sm:w-64 border-gray-300 rounded-md shadow-sm text-sm font-mono p-2.5 focus:border-indigo-500 focus:ring-indigo-500">
                                    <button type="button" id="btn-cloud-pair"
                                            class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-md hover:bg-indigo-700 text-sm font-bold shadow-sm whitespace-nowrap">
                                        🔗 Hubungkan
                                    </button>
                                </form>
                                <div id="cloud-pair-msg" class="hidden mt-2 text-sm"></div>
                            </div>
                        @endif

                    </div>

                    <div id="cloud-pair-result" class="hidden mt-4 text-sm rounded-lg px-4 py-3"></div>
                </div>

                {{-- [TARIK VIA CLOUD] --}}
                <div class="bg-white p-6 rounded-xl shadow-md border-l-4 mb-6 transition-all duration-300 hover:shadow-lg">
                    <div class="flex items-start gap-4">
                        <div class="text-4xl">☁️</div>
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-gray-800 mb-1">Tarik Data Pokok via Cloud</h3>
                            <p class="text-sm text-gray-500">
                                Ambil Data Pokok dari Cloud eduXid - tanpa perlu satu jaringan dengan Admin.
                                <br>
                                <span class="text-xs text-green-600 font-bold">
                                    ℹ️ Data nilai, absensi, dan bank soal yang sudah Anda input tidak akan terhapus.
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="button" id="btn-pull-master-cloud"
                                class="w-full inline-flex items-center justify-center gap-2 bg-red-600 text-white px-4 py-2.5 rounded-md hover:bg-red-700 text-sm font-bold shadow-sm disabled:opacity-50">
                            ⬇️ Tarik Data Pokok via Cloud
                        </button>
                        <div id="pull-master-cloud-result" class="hidden mt-3 text-sm rounded-lg px-4 py-3"></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-6 rounded-xl shadow-md border border-blue-100 border-t-4 border-blue-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="text-3xl mb-2">🌐</div>
                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Sinkron via Jaringan Lokal</h4>
                        <p class="text-sm text-gray-500 mb-4 flex-grow">Tarik Data Pokok langsung dari komputer Admin (Server) yang berada di jaringan lokal.</p>
                        <div class="mt-auto">
                            <a href="{{ route('guru.pengaturan') }}" class="w-full block text-center bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 transition-colors text-sm font-bold">Buka Pengaturan</a>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-green-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="text-3xl mb-2">📂</div>
                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Impor Data Pokok</h4>
                        <p class="text-sm text-gray-500 mb-4 flex-grow">Unggah file Data Pokok <code>(m2g_*.json)</code> yang diberikan oleh Admin eduXid.</p>
                        <form action="{{ route('guru.imporMaster') }}" method="POST" enctype="multipart/form-data" class="mt-auto">
                            @csrf
                            <div class="mb-3">
                                <input type="file" name="json_file" accept=".json,.txt" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2">
                            </div>
                            <button type="submit" class="w-full bg-green-600 text-white py-2 rounded-md hover:bg-green-700 text-sm font-bold">Impor Data Pokok</button>
                        </form>
                    </div>
                </div>

            {{-- ========================================================= --}}
            {{-- Dasbor ADMIN                                          --}}
            {{-- ========================================================= --}}
            @elseif($role == 'admin')

                @if(!($stats['is_synced'] ?? false))
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow-sm flex items-center">
                    <span class="text-2xl mr-3">⚠️</span>
                    <div>
                        <p class="font-bold">Belum ada Data Pokok.</p>
                        <p class="text-sm">Lakukan sinkronisasi dengan Dapodik terlebih dahulu atau impor manual data Excel (mulai dari Data Sekolah).</p>
                    </div>
                </div>
                @endif

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6 transition-all duration-300 hover:shadow-lg">
                    <div class="p-6 text-gray-900 flex justify-between items-center">
                        <div>
                            <h1 class="text-2xl font-bold text-indigo-600">🏫 {{ $stats['sekolah']->nama ?? 'Nama Sekolah' }}</h1>
                            <p class="text-xl font-bold text-gray-500 mt-1">NPSN: {{ $stats['sekolah']->npsn ?? '-' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xl font-bold text-indigo-600">T.P. {{ $stats['tahunAjaran'] ?? '-' }}</p>
                            <p class="text-sm font-bold text-gray-500">Semester {{ $stats['semester'] ?? '-' }}</p>
                            <div class="mt-2 bg-indigo-50 border border-indigo-200 text-indigo-700 px-3 py-1 rounded-md text-xs font-mono font-bold inline-block">
                                🌐 IP Address Komputer Admin (Server): {{ $stats['server_ip'] ?? '127.0.0.1' }}:8000
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 border-t-4 border-blue-500 transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="flex items-center">
                            <div class="text-4xl mr-4">👨‍🏫</div>
                            <div><div class="text-3xl font-bold text-gray-800">{{ $stats['totalGuru'] ?? 0 }}</div><div class="font-bold text-gray-500">Total Guru</div></div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 border-t-4 border-green-500 transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="flex items-center">
                            <div class="text-4xl mr-4">🧑‍🎓</div>
                            <div><div class="text-3xl font-bold text-gray-800">{{ $stats['totalSiswa'] ?? 0 }}</div><div class="font-bold text-gray-500">Total Murid</div></div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 border-t-4 border-yellow-500 transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="flex items-center">
                            <div class="text-4xl mr-4">🏫</div>
                            <div><div class="text-3xl font-bold text-gray-800">{{ $stats['totalRombel'] ?? 0 }}</div><div class="font-bold text-gray-500">Total Rombel</div></div>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 border-t-4 border-purple-500 transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="flex items-center">
                            <div class="text-4xl mr-4">📚</div>
                            <div><div class="text-3xl font-bold text-gray-800">{{ $stats['totalMapel'] ?? 0 }}</div><div class="font-bold text-gray-500">Mata Pelajaran</div></div>
                        </div>
                    </div>
                </div>

                {{-- KARTU SETUP CLOUD ADMIN [G1] --}}
                <div class="bg-white p-6 rounded-xl shadow-md border-l-4 mb-8 transition-all duration-300 hover:shadow-lg {{ ($stats['cloudStatus']['ready'] ?? false) ? 'border-green-500' : 'border-yellow-500' }}">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div class="flex items-start gap-4">
                            <div class="text-4xl">{{ ($stats['cloudStatus']['ready'] ?? false) ? '☁️' : '⚠️' }}</div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">
                                    Koneksi eduXid Cloud
                                    <span class="ml-2 text-xs font-bold px-2 py-1 rounded-full align-middle {{ ($stats['cloudStatus']['ready'] ?? false) ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">
                                        {{ ($stats['cloudStatus']['ready'] ?? false) ? 'TERHUBUNG' : 'BELUM TERKONFIGURASI' }}
                                    </span>
                                </h3>
                                @if ($stats['cloudStatus']['ready'] ?? false)
                                    <p class="text-sm text-gray-500">
                                        Kunci sekolah: <code class="text-xs">{{ $stats['cloudStatus']['token_preview'] }}</code>
                                        · sumber: {{ $stats['cloudStatus']['token_source'] }}
                                    </p>
                                @else
                                    <p class="text-sm text-gray-500">
                                        Sistem belum terhubung ke Cloud. Fitur <b>eduXid Murid</b>, <b>eduXid Guru</b>, dan sinkronisasi antar perangkat <b>belum aktif</b>.
                                    </p>
                                    <p class="mt-2 text-sm font-semibold text-indigo-700">
                                        Hubungi Admin Pusat untuk mendapatkan <b>Kode Pairing Sekolah</b> melalui WhatsApp
                                        <a href="https://wa.me/628225318189" target="_blank" rel="noopener noreferrer" class="font-black underline decoration-indigo-300 underline-offset-2 hover:text-indigo-900">082253181899</a>.
                                    </p>
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-2 shrink-0">
                            <a href="{{ route('admin.eduxid-cloud') }}" class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-md hover:bg-indigo-700 text-sm font-bold shadow-sm whitespace-nowrap">
                                ⚙️ Buka eduXid Cloud
                            </a>
                            <button type="button" id="btn-cloud-test" class="inline-flex items-center justify-center gap-2 bg-gray-100 text-gray-700 border border-gray-300 px-4 py-2.5 rounded-md hover:bg-gray-200 text-sm font-bold whitespace-nowrap">
                                🔌 Uji Koneksi
                            </button>
                        </div>
                    </div>
                    <div id="cloud-test-result" class="hidden mt-4 text-sm rounded-lg px-4 py-3"></div>
                </div>


                {{-- ============================================================ --}}
                {{-- [SYNC-HEALTH] KARTU STATUS SINKRONISASI (ADMIN SAJA)          --}}
                {{-- Menampilkan: versi master, daemon, antrean, dead-letter       --}}
                {{-- ============================================================ --}}
                @if(isset($stats['syncHealth']))
                <div class="bg-white p-6 rounded-xl shadow-md border-l-4 mb-8 transition-all duration-300 hover:shadow-lg
                    {{ ($stats['syncHealth']['daemon_alive'] ?? false) ? 'border-green-500' : 'border-red-500' }}">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">🔄 Status Sinkronisasi</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <p class="text-xs font-bold text-gray-500 mb-1">📦 VERSI MASTER (TERPASANG / TER-PUSH)</p>
                            <p class="text-sm font-mono font-bold text-gray-800">{{ $stats['syncHealth']['master_applied'] }} / {{ $stats['syncHealth']['master_pushed'] }}</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <p class="text-xs font-bold text-gray-500 mb-1">⚙️ DAEMON SINKRON</p>
                            <p class="text-sm font-bold {{ ($stats['syncHealth']['daemon_alive'] ?? false) ? 'text-green-700' : 'text-red-700' }}">
                                {{ ($stats['syncHealth']['daemon_alive'] ?? false) ? '🟢 HIDUP' : '🔴 MATI' }}
                            </p>
                            @if(!($stats['syncHealth']['daemon_alive'] ?? false))
                                <p class="text-xs text-gray-500 mt-1">Jalankan eduXid.exe untuk menyalakan.</p>
                            @endif
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                            <p class="text-xs font-bold text-gray-500 mb-1">📮 ANTREAN / DEAD-LETTER</p>
                            <p class="text-sm font-bold text-gray-800">{{ $stats['syncHealth']['outbox_pending'] }} / {{ $stats['syncHealth']['dead_letters'] }}</p>
                            @if(($stats['syncHealth']['dead_letters'] ?? 0) > 0)
                                <p class="text-xs text-red-600 mt-1">Jalankan: php artisan eduxid:replay-dead</p>
                            @endif
                        </div>
                    </div>
                </div>
                @endif

                {{-- SINKRONISASI DATA POKOK --}}
                <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-l-4 border-indigo-500 mb-8 transition-all duration-300 hover:shadow-lg">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">🚀 Sinkronisasi Data Pokok</h3>
                    <p class="text-[14px] font-bold mb-4 flex-grow">Pilih salah satu metode sinkronisasi Data Pokok di bawah ini.</p>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-blue-50 p-6 rounded-lg border-t-4 border-blue-500 flex flex-col transition-all duration-300 hover:shadow-md hover:-translate-y-1">
                            <div class="text-3xl mb-2">🌐</div>
                            <h4 class="font-bold text-gray-800 mb-2 text-lg">Sinkron via API Dapodik (Disarankan)</h4>
                            <p class="text-sm text-gray-500 mb-4 flex-grow">Tarik Data Pokok dari Dapodik pada komputer yang sama (127.0.0.1:5774). Pastikan Anda sudah memiliki kode token yang diperoleh dari Pengaturan Web Service Lokal Dapodik.</p>
                            <form action="{{ route('admin.impor.syncApi') }}" method="POST" class="mt-auto">
                                @csrf
                                <input type="text" name="npsn" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm mb-2" placeholder="NPSN Sekolah" required autocomplete="off">
                                <input type="password" name="token" class="w-full border-gray-300 rounded-md shadow-sm p-2 text-sm mb-3" placeholder="Token Web Service Dapodik" required autocomplete="off">
                                <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 text-sm font-bold">Sinkron Sekarang</button>
                            </form>
                        </div>

                        <div class="bg-red-50 p-6 rounded-lg border-t-4 border-red-500 flex flex-col transition-all duration-300 hover:shadow-md hover:-translate-y-1">
                            <div class="text-3xl mb-2">📊</div>
                            <h4 class="font-bold text-gray-800 mb-2 text-lg">Impor Manual Data Excel</h4>
                            <p class="text-sm text-gray-500 mb-2 flex-grow">Unduh template Excel sesuai jenis data. Isi sheet DATA_IMPOR; sheet CONTOH_VALID dan PANDUAN hanya untuk referensi, tidak ikut diimpor.</p>

                            <div class="bg-white border border-red-200 rounded-md p-3 mb-3">
                                <p class="text-xs font-bold text-gray-700 mb-1">⚠️ Urutan impor:</p>
                                <p class="text-xs text-gray-600">
                                    <b>1. Sekolah</b> → <b>2. GTK</b> → <b>3. Kelas</b> → <b>4. Murid</b> → <b>5. Mapel</b> → <b>6. Pembelajaran</b>
                                </p>
                                <p class="text-xs text-gray-500 mt-1">Password awal akun baru dibuat <b>acak</b>. Ambil melalui <b>Pengaturan → Aktivasi Akun</b>; jangan gunakan NPSN sebagai password.</p>
                            </div>

                            <div class="flex flex-wrap border-b border-gray-200 mb-2">
                                <button id="tab-sekolah" onclick="switchTab('sekolah')" class="flex-1 text-gray-500 border-b-2 border-blue-600 py-2 text-xs font-bold">1. Sekolah</button>
                                <button id="tab-guru" onclick="switchTab('guru')" class="flex-1 text-gray-500 border-b-2 border-gray-300 py-2 text-xs font-bold">2. GTK</button>
                                <button id="tab-kelas" onclick="switchTab('kelas')" class="flex-1 text-gray-500 border-b-2 border-gray-300 py-2 text-xs font-bold">3. Kelas</button>
                                <button id="tab-siswa" onclick="switchTab('siswa')" class="flex-1 text-gray-500 border-b-2 border-gray-300 py-2 text-xs font-bold">4. Murid</button>
                                <button id="tab-mapel" onclick="switchTab('mapel')" class="flex-1 text-gray-500 border-b-2 border-gray-300 py-2 text-xs font-bold">5. Mapel</button>
                                <button id="tab-pembelajaran" onclick="switchTab('pembelajaran')" class="flex-1 text-gray-500 border-b-2 border-gray-300 py-2 text-xs font-bold">6. Pembelajaran</button>
                            </div>
                            <form id="form-sekolah" action="{{ route('admin.imporExcelSekolah') }}" method="POST" enctype="multipart/form-data" class="block">
                                @csrf
                                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-2">
                                <button type="submit" class="w-full bg-orange-500 text-white py-2 rounded-md hover:bg-orange-600 text-sm font-bold">Impor Excel Sekolah</button>
                                <a href="{{ route('admin.impor.templateSekolah') }}" class="block text-center text-indigo-600 hover:underline text-xs mt-2">⬇️ Unduh Template Sekolah</a>
                            </form>
                            <form id="form-guru" action="{{ route('admin.imporExcelGuru') }}" method="POST" enctype="multipart/form-data" class="hidden">
                                @csrf
                                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-2">
                                <button type="submit" class="w-full bg-orange-500 text-white py-2 rounded-md hover:bg-orange-600 text-sm font-bold">Impor Excel GTK</button>
                                <a href="{{ route('admin.impor.templateGuru') }}" class="block text-center text-indigo-600 hover:underline text-xs mt-2">⬇️ Unduh Template GTK</a>
                            </form>
                            <form id="form-kelas" action="{{ route('admin.imporExcelKelas') }}" method="POST" enctype="multipart/form-data" class="hidden">
                                @csrf
                                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-2">
                                <button type="submit" class="w-full bg-orange-500 text-white py-2 rounded-md hover:bg-orange-600 text-sm font-bold">Impor Excel Kelas</button>
                                <a href="{{ route('admin.impor.templateKelas') }}" class="block text-center text-indigo-600 hover:underline text-xs mt-2">⬇️ Unduh Template Kelas</a>
                            </form>
                            <form id="form-siswa" action="{{ route('admin.imporExcelSiswa') }}" method="POST" enctype="multipart/form-data" class="hidden">
                                @csrf
                                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-2">
                                <button type="submit" class="w-full bg-orange-500 text-white py-2 rounded-md hover:bg-orange-600 text-sm font-bold">Impor Excel Murid</button>
                                <a href="{{ route('admin.impor.templateSiswa') }}" class="block text-center text-indigo-600 hover:underline text-xs mt-2">⬇️ Unduh Template Murid</a>
                            </form>
                            <form id="form-mapel" action="{{ route('admin.imporExcelMapel') }}" method="POST" enctype="multipart/form-data" class="hidden">
                                @csrf
                                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-2">
                                <button type="submit" class="w-full bg-orange-500 text-white py-2 rounded-md hover:bg-orange-600 text-sm font-bold">Impor Excel Mapel</button>
                                <a href="{{ route('admin.impor.templateMapel') }}" class="block text-center text-indigo-600 hover:underline text-xs mt-2">⬇️ Unduh Template Mapel</a>
                            </form>
                            <form id="form-pembelajaran" action="{{ route('admin.imporExcelPembelajaran') }}" method="POST" enctype="multipart/form-data" class="hidden">
                                @csrf
                                <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required class="block w-full border border-gray-300 rounded-md shadow-sm text-sm text-gray-600 p-2 mb-2">
                                <button type="submit" class="w-full bg-orange-500 text-white py-2 rounded-md hover:bg-orange-600 text-sm font-bold">Impor Excel Pembelajaran</button>
                                <a href="{{ route('admin.impor.templatePembelajaran') }}" class="block text-center text-indigo-600 hover:underline text-xs mt-2">⬇️ Unduh Template Pembelajaran</a>
                            </form>
                            <script>
                                function switchTab(tab) {
                                    ['sekolah', 'guru', 'kelas', 'siswa', 'mapel', 'pembelajaran'].forEach(t => {
                                        document.getElementById('form-' + t).classList.add('hidden');
                                        document.getElementById('tab-' + t).classList.remove('border-blue-600', 'text-blue-600');
                                        document.getElementById('tab-' + t).classList.add('text-gray-500');
                                    });
                                    document.getElementById('form-' + tab).classList.remove('hidden');
                                    document.getElementById('tab-' + tab).classList.add('border-blue-600', 'text-blue-600');
                                    document.getElementById('tab-' + tab).classList.remove('text-gray-500');
                                }
                            </script>
                        </div>

                        <div class="bg-purple-50 p-6 rounded-lg border-t-4 border-purple-600 flex flex-col transition-all duration-300 hover:shadow-md hover:-translate-y-1">
                            <div class="text-3xl mb-2">📦</div>
                            <h4 class="font-bold text-gray-800 mb-2 text-lg">Bagikan Data Pokok (Jika Tidak Ada Koneksi Internet)</h4>
                            <p class="text-sm text-gray-500 mb-4 flex-grow">Bagikan file Data Pokok (m2g_NPSN_SEKOLAH*.json) ke GTK untuk diupload ke eduXid GTK via Pengaturan Offline. Pastikan data sudah disesuaikan dengan kondisi riil sekolah.</p>
                            <div class="mt-auto">
                                @if($stats['is_synced'] ?? false)
                                    <a href="{{ route('admin.eksporMaster') }}" class="block w-full text-center bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 text-sm font-bold whitespace-nowrap shadow-sm transition-colors">Download Data Pokok</a>
                                @else
                                    <button disabled class="block w-full bg-gray-300 text-gray-500 px-4 py-3 rounded-md cursor-not-allowed text-sm font-bold whitespace-nowrap shadow-sm">Ekspor Data Pokok</button>
                                @endif
                            </div>
                        </div>

                        <div class="bg-indigo-50 p-6 rounded-lg border-t-4 border-indigo-500 flex flex-col transition-all duration-300 hover:shadow-md hover:-translate-y-1">
                            <div class="text-3xl mb-2">🧰</div>
                            <h4 class="font-bold text-gray-800 mb-2 text-lg">Siapkan Paket GTK (Pastikan eduXid Cloud Sudah Terhubung)</h4>
                            <p class="text-sm text-gray-500 mb-4 flex-grow">Buat paket aplikasi untuk setiap GTK dengan Data Pokok dan pairing yang disiapkan oleh admin, lalu bagikan paket kepada pemiliknya.</p>
                            <div class="mt-auto">
                                @if(Route::has('admin.gtk-bundles.index'))
                                    <a href="{{ route('admin.gtk-bundles.index') }}" class="block w-full text-center bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 text-sm font-bold whitespace-nowrap shadow-sm transition-colors">Buka Persiapan Paket GTK</a>
                                @else
                                    <button type="button" disabled class="block w-full bg-gray-300 text-gray-500 px-4 py-2 rounded-md cursor-not-allowed text-sm font-bold whitespace-nowrap shadow-sm" title="Pasang fitur Paket GTK untuk membuka persiapan.">Paket GTK Belum Tersedia</button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

            {{-- ========================================================= --}}
            {{-- Dasbor TENDIK NORMAL                                  --}}
            {{-- ========================================================= --}}
            @elseif($role == 'tendik')

                <div class="bg-gradient-to-r from-indigo-600 to-blue-500 rounded-xl shadow-md p-6 mb-8 text-white flex flex-col md:flex-row justify-between items-center transition-all duration-300 hover:shadow-lg">
                    <div class="mb-4 md:mb-0 text-center md:text-left">
                        <h1 class="text-2xl font-bold">Selamat Datang, {{ auth()->user()->name }}!</h1>
                        <p class="text-sm opacity-80 mt-1">Kelola administrasi dan surat-menyurat sekolah dengan cepat.</p>
                    </div>
                    <a href="{{ route('tu.surat.index') }}" class="bg-white text-indigo-600 px-5 py-3 rounded-lg font-bold text-sm hover:bg-indigo-50 shadow-sm whitespace-nowrap transition-colors">🖨️ Buat Surat Dinas</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-blue-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="text-3xl mb-2">📄</div>
                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Generator Surat</h4>
                        <p class="text-sm text-gray-500 mb-4 flex-grow">Buat Surat Panggilan, Undangan, Keterangan Lulus, hingga Surat Kepegawaian dengan kop sekolah otomatis.</p>
                        <a href="{{ route('tu.surat.index') }}" class="text-indigo-600 font-bold text-sm hover:underline">Buka Menu Surat →</a>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-green-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="text-3xl mb-2">🧑‍🎓</div>
                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Data Murid</h4>
                        <p class="text-sm text-gray-500 mb-4 flex-grow">Cari data murid, cetak biodata, atau periksa NISN untuk keperluan administrasi sekolah.</p>
                        <a href="{{ route('siswa.index') }}" class="text-indigo-600 font-bold text-sm hover:underline">Lihat Data Murid →</a>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-purple-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="text-3xl mb-2">⚙️</div>
                        <h4 class="font-bold text-gray-800 mb-2 text-lg">Pengaturan</h4>
                        <p class="text-sm text-gray-500 mb-4 flex-grow">Atur IP Address lokal atau lakukan backup data pekerjaan Anda ke komputer Admin.</p>
                        <a href="{{ route('guru.pengaturan') }}" class="text-indigo-600 font-bold text-sm hover:underline">Buka Pengaturan →</a>
                    </div>
                </div>

            {{-- ========================================================= --}}
            {{-- Dasbor GTK NORMAL                                     --}}
            {{-- ========================================================= --}}
            @elseif($isTeacherDashboard)

                <div class="mb-6 bg-indigo-50 border border-indigo-200 text-indigo-700 px-4 py-3 rounded-md text-sm font-mono font-bold flex flex-col sm:flex-row justify-between items-start sm:items-center">
                    <div>
                        @php $ip = function_exists('get_local_ip') ? get_local_ip() : '127.0.0.1'; @endphp
                        🌐 IP Address Komputer Guru{{ $canUseUjian ? ' (Server CBT)' : '' }}:
                        <span class="text-indigo-900">{{ $ip }}:8000</span>
                    </div>
                    @if($canUseUjian)
                        <a href="{{ route('guru.cbt') }}" class="text-xs text-white bg-indigo-600 px-3 py-1 rounded mt-2 sm:mt-0 font-sans font-bold hover:bg-indigo-700">Buka Menu CBT</a>
                    @endif
                </div>

                <div class="bg-gradient-to-r from-indigo-600 to-blue-500 rounded-xl shadow-md p-6 mb-8 text-white flex flex-col md:flex-row justify-between items-center transition-all duration-300 hover:shadow-lg">
                    <div class="mb-4 md:mb-0 text-center md:text-left">
                        <h1 class="text-2xl font-bold">Selamat Datang, {{ auth()->user()->name }}!</h1>
                    </div>
                    <a href="{{ route('guru.mengajar') }}" class="bg-white text-indigo-600 px-5 py-3 rounded-lg font-bold text-sm hover:bg-indigo-50 shadow-sm whitespace-nowrap transition-colors">Buka Buku Nilai</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 flex items-center transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="bg-blue-50 p-4 rounded-full mr-5 flex items-center justify-center"><span class="text-3xl">🏫</span></div>
                        <div><div class="text-3xl font-bold text-gray-800">{{ $stats['jumlah_kelas'] ?? 0 }}</div><div class="text-sm font-bold text-gray-500 mt-1">Kelas Diajar</div></div>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 flex items-center transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="bg-green-50 p-4 rounded-full mr-5 flex items-center justify-center"><span class="text-3xl">🧑‍🎓</span></div>
                        <div><div class="text-3xl font-bold text-gray-800">{{ $stats['jumlah_siswa'] ?? 0 }}</div><div class="text-sm font-bold text-gray-500 mt-1">Murid Diajar</div></div>
                    </div>
                    <div class="bg-white rounded-xl shadow-md border border-gray-100 p-6 flex items-center transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <div class="bg-purple-50 p-4 rounded-full mr-5 flex items-center justify-center"><span class="text-3xl">📚</span></div>
                        <div><div class="text-3xl font-bold text-gray-800">{{ $stats['jumlah_mapel'] ?? 0 }}</div><div class="text-sm font-bold text-gray-500 mt-1">Mapel Diajar</div></div>
                    </div>
                </div>

                {{-- [CLOUD-PAIRING] Kartu pairing - GTK normal --}}
                <div class="bg-white p-6 rounded-xl shadow-md border-l-4 mb-8 transition-all duration-300 hover:shadow-lg
                    {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? 'border-green-500' : 'border-yellow-500' }}">

                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">

                        <div class="flex items-start gap-4">
                            <div class="text-4xl">
                                {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? '☁️' : '⚠️' }}
                            </div>

                            <div>
                                <h3 class="text-lg font-bold text-gray-800 mb-1">
                                    eduXid Cloud
                                    <span class="ml-2 text-xs font-bold px-2 py-1 rounded-full align-middle
                                        {{ ($stats['cloud_pairing']['is_paired'] ?? false)
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-yellow-100 text-yellow-700' }}">
                                        {{ ($stats['cloud_pairing']['is_paired'] ?? false) ? 'TERHUBUNG' : 'BELUM TERHUBUNG' }}
                                    </span>
                                </h3>

                                @if ($stats['cloud_pairing']['is_paired'] ?? false)
                                    <p class="text-sm text-gray-500">
                                        Laptop ini terhubung ke eduXid Cloud.
                                        {{ auth()->user()?->roleName() === 'tendik' ? 'Perangkat GTK ini memiliki pairing Cloud aktif.' : 'Sinkronisasi konten dan nilai ke perangkat murid aktif.' }}
                                    </p>
                                @else
                                    <p class="text-sm text-gray-500">
                                        {{ auth()->user()?->roleName() === 'tendik' ? 'Pairing akun Tendik belum terkonfirmasi.' : 'Sinkronisasi konten & nilai ke perangkat murid belum aktif.' }}
                                        Masukkan <b>{{ auth()->user()?->roleName() === 'tendik' ? 'Kode GTK Tendik' : 'Kode Guru' }}</b> dari Admin Sekolah.
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if (!($stats['cloud_pairing']['is_paired'] ?? false))
                            <div class="shrink-0 w-full sm:w-auto">
                                <form id="cloud-pair-form" onsubmit="return false;" class="flex flex-col sm:flex-row gap-2">
                                    @csrf
                                    <input type="text" id="cloud-pair-code" name="enrollment_token"
                                           placeholder="{{ auth()->user()?->roleName() === 'tendik' ? 'Tempel Kode GTK Tendik dari Admin' : 'Tempel Kode Guru dari Admin' }}"
                                           autocomplete="off"
                                           class="w-full sm:w-64 border-gray-300 rounded-md shadow-sm text-sm font-mono p-2.5 focus:border-indigo-500 focus:ring-indigo-500">
                                    <button type="button" id="btn-cloud-pair"
                                            class="inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-4 py-2.5 rounded-md hover:bg-indigo-700 text-sm font-bold shadow-sm whitespace-nowrap">
                                        🔗 Hubungkan
                                    </button>
                                </form>
                                <div id="cloud-pair-msg" class="hidden mt-2 text-sm"></div>
                            </div>
                        @endif

                    </div>

                    <div id="cloud-pair-result" class="hidden mt-4 text-sm rounded-lg px-4 py-3"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-purple-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <h3 class="text-lg font-bold text-gray-800 mb-3">📌 Pengingat</h3>
                        <p class="text-sm text-gray-500 leading-relaxed">Pastikan Anda melakukan input nilai harian, absensi, dan penilaian sikap setiap pertemuan. Jika sudah selesai bekerja, segera kirim hasil pekerjaan Anda ke komputer Admin (server). Rekap nilai akhir akan otomatis masuk ke rapor murid.</p>
                        <a href="{{ route('guru.pengaturan') }}" class="text-indigo-600 font-bold text-sm hover:underline mt-2">Kirim Hasil Pekerjaan Guru →</a>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-md border border-gray-100 border-t-4 border-purple-500 flex flex-col transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
                        <h3 class="text-lg font-bold text-gray-800 mb-3">⚙️ Mode Offline</h3>
                        <p class="text-sm text-gray-500 leading-relaxed mb-4">Jaringan internet bermasalah? Anda dapat mengimpor Data Pokok dan mengekspor hasil input melalui menu Pengaturan Offline.</p>
                        <a href="{{ route('guru.pengaturan') }}" class="text-indigo-600 font-bold text-sm hover:underline">Pengaturan Offline →</a>
                    </div>
                </div>

            @else
                <div class="bg-white p-6 rounded-xl shadow-md text-center">
                    <p class="text-gray-500">Selamat datang di eduXid. Role akun Anda belum memiliki Dasbor khusus.</p>
                </div>
            @endif

        </div>
    </div>

    @auth
        @if($role == 'admin')
            {{-- SCRIPT ADMIN - UJI KONEKSI CLOUD [G1] --}}
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                'use strict';

                const btn = document.getElementById('btn-cloud-test');
                const res = document.getElementById('cloud-test-result');

                if (!btn || !res) { return; }

                const statusUrl = @json(
                    Route::has('admin.eduxid-cloud.setup.status')
                        ? route('admin.eduxid-cloud.setup.status')
                        : null
                );

                const escHtml = (value) => String(value ?? '')
                    .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;').replaceAll('"', '&quot;');

                btn.addEventListener('click', async function () {
                    if (!statusUrl) {
                        res.classList.remove('hidden');
                        res.classList.add('bg-red-50', 'text-red-700');
                        res.innerHTML = '✕ Endpoint status cloud tidak terdaftar.';
                        return;
                    }

                    btn.disabled = true;
                    btn.textContent = '⏳ Menguji...';
                    res.classList.remove('hidden', 'bg-green-50', 'text-green-700', 'bg-red-50', 'text-red-700');
                    res.textContent = 'Menghubungi server...';

                    try {
                        const response = await fetch(statusUrl, {
                            method: 'GET',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            credentials: 'same-origin',
                        });

                        const data = await response.json().catch(() => null);

                        if (!response.ok || !data || data.success !== true) {
                            throw new Error((data && data.message) || ('HTTP ' + response.status));
                        }

                        const status = data.status || {};

                        if (status.ready) {
                            res.classList.add('bg-green-50', 'text-green-700');
                            res.innerHTML = '✓ Koneksi ke eduXid Cloud SIAP. Kunci: <code>' + escHtml(status.token_preview || '') + '</code> · sumber: ' + escHtml(status.token_source || '') + '.';
                        } else {
                            res.classList.add('bg-red-50', 'text-red-700');
                            res.innerHTML = '✕ Belum terkonfigurasi. Buka eduXid Cloud -> kartu Setup Cloud.';
                        }
                    } catch (error) {
                        res.classList.add('bg-red-50', 'text-red-700');
                        res.innerHTML = '✕ Tidak dapat menghubungi server lokal: ' + escHtml(error.message);
                    } finally {
                        btn.disabled = false;
                        btn.textContent = '🔌 Uji Koneksi';
                    }
                });
            });
            </script>
        @endif
    @endauth

    @auth
        @if($isTeacherDashboard || $role === 'tendik')
            {{-- SCRIPT PAIRING CLOUD + TARIK MASTER CLOUD --}}
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                'use strict';

                /* PAIRING CLOUD */
                const form = document.getElementById('cloud-pair-form');
                const btn = document.getElementById('btn-cloud-pair');
                const input = document.getElementById('cloud-pair-code');
                const msg = document.getElementById('cloud-pair-msg');
                const result = document.getElementById('cloud-pair-result');

                if (form && btn && input && msg) {
                    const pairUrl = @json(
                        Route::has('cloud.pair')
                            ? route('cloud.pair')
                            : (Route::has('lms.cloud.pair') ? route('lms.cloud.pair') : null)
                    );

                    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    const csrfInput = form.querySelector('input[name="_token"]');
                    const csrfToken = (csrfMeta && csrfMeta.getAttribute('content')) || (csrfInput ? csrfInput.value : '');

                    const escHtml = (value) => String(value ?? '')
                        .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
                        .replaceAll('>', '&gt;').replaceAll('"', '&quot;');

                    function showMsg(text, type) {
                        msg.classList.remove('hidden', 'bg-red-50', 'border-red-200', 'text-red-700',
                            'bg-green-50', 'border-green-200', 'text-green-700',
                            'bg-blue-50', 'border-blue-200', 'text-blue-700');
                        msg.classList.add('mt-2', 'rounded-xl', 'px-4', 'py-3', 'text-sm', 'border');
                        const cls = type === 'ok'
                            ? ['bg-green-50', 'border-green-200', 'text-green-700']
                            : type === 'info'
                            ? ['bg-blue-50', 'border-blue-200', 'text-blue-700']
                            : ['bg-red-50', 'border-red-200', 'text-red-700'];
                        cls.forEach(c => msg.classList.add(c));
                        msg.textContent = text;
                    }

                    btn.addEventListener('click', async function () {
                        const code = (input.value || '').trim();

                        if (!pairUrl) {
                            showMsg('⚠️ Endpoint pairing tidak tersedia.', 'err');
                            return;
                        }

                        if (!code) {
                            showMsg('⚠️ Kode pairing GTK wajib diisi.', 'err');
                            input.focus();
                            return;
                        }

                        btn.disabled = true;
                        btn.textContent = '⏳ Memasangkan...';
                        showMsg('Menghubungi eduXid Cloud...', 'info');

                        try {
                            const response = await fetch(pairUrl, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify({ enrollment_token: code }),
                            });

                            const raw = await response.text();
                            let data = null;
                            try { data = JSON.parse(raw); } catch (e) {
                                console.error('[eduXid] Response pairing bukan JSON:', raw);
                            }

                            if (!response.ok || !data || data.success !== true) {
                                throw new Error((data && (data.message || data.error)) || ('HTTP ' + response.status));
                            }

                            showMsg('✓ ' + (data.message || 'Perangkat berhasil dipasangkan.') + ' Halaman akan dimuat ulang.', 'ok');

                            setTimeout(function () { window.location.reload(); }, 1500);
                        } catch (error) {
                            console.error('[eduXid] Pairing error:', error);
                            showMsg('⚠️ ' + escHtml(error.message), 'err');
                            btn.disabled = false;
                            btn.textContent = '🔗 Hubungkan';
                        }
                    });
                }

                /* TARIK DATA POKOK VIA CLOUD */
                const btnPullMaster = document.getElementById('btn-pull-master-cloud');
                if (btnPullMaster) {
                    const resPull = document.getElementById('pull-master-cloud-result');

                    btnPullMaster.addEventListener('click', async function () {
                        if (!confirm('Tarik Data Pokok via Cloud?\n\nData master akan diperbarui.\nData nilai Anda tetap aman.\n\nJangan tutup halaman.')) {
                            return;
                        }

                        btnPullMaster.disabled = true;
                        const originalText = btnPullMaster.innerHTML;
                        btnPullMaster.innerHTML = '⏳ Menarik data dari Cloud...';

                        resPull.classList.remove('hidden', 'bg-green-50', 'text-green-700', 'bg-red-50', 'text-red-700');
                        resPull.textContent = 'Menghubungi eduXid Cloud...';

                        try {
                            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

                            const response = await fetch('{{ route("guru.pullMasterDataCloud") }}', {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': csrfToken,
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                                credentials: 'same-origin',
                            });

                            const data = await response.json().catch(() => null);

                            if (!response.ok || !data) {
                                throw new Error((data && data.message) || ('HTTP ' + response.status));
                            }

                            resPull.classList.add('bg-green-50', 'text-green-700');
                            resPull.textContent = data.message;

                        } catch (error) {
                            resPull.classList.add('bg-red-50', 'text-red-700');
                            resPull.textContent = '✕ ' + error.message;
                        } finally {
                            btnPullMaster.disabled = false;
                            btnPullMaster.innerHTML = originalText;
                        }
                    });
                }
            });
            </script>
        @endif
    @endauth

@if($canUseBroadcast)
<script>
(function () {
    'use strict';
    const banner = document.getElementById('dashboard-broadcast-banner');
    const status = document.getElementById('dashboard-broadcast-status');
    const endpoint = @json(route('broadcast.preview'));
    if (!banner) return;
    let busy = false;
    async function refreshBroadcast() {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const response = await fetch(endpoint, {
                method: 'GET', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                cache: 'no-store'
            });
            if (!response.ok) {
                if (status) status.textContent = 'Pemeriksaan pesan gagal (HTTP ' + response.status + '). Pesan lokal tetap tersedia.';
                return;
            }
            const data = await response.json();
            if (data.success !== true) return;
            if (status && data.inbox_status && typeof data.inbox_status.message === 'string') {
                status.textContent = data.inbox_status.message;
            }
            if (typeof data.html === 'string' && banner.innerHTML !== data.html) banner.innerHTML = data.html;
            // Banner bisa diganti sepenuhnya saat polling: gunakan badge pada DOM terbaru.
            const counter = banner.querySelector('#dashboard-broadcast-unread');
            if (counter) {
                const count = Math.max(0, Number(data.unread_count) || 0);
                counter.textContent = String(count);
                counter.classList.toggle('hidden', count === 0);
            }
        } catch (error) {
            if (status) status.textContent = 'Belum dapat memeriksa pesan. Pesan tersimpan tetap tersedia offline.';
            // Offline-first: banner lokal tetap tampil; polling berikutnya mencoba lagi.
        } finally {
            busy = false;
        }
    }
    refreshBroadcast();
    const timer = window.setInterval(refreshBroadcast, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshBroadcast(); });
    window.addEventListener('online', refreshBroadcast);
    window.addEventListener('pagehide', () => window.clearInterval(timer), { once: true });
})();
</script>
@endif
</x-app-layout>
