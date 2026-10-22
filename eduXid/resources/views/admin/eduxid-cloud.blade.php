<x-app-layout>
<x-slot name="header">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">eduXid Cloud</h2>
            <p class="text-sm text-gray-500 mt-0.5">Kelola koneksi Cloud, kode pairing perangkat, dan sinkronisasi akun untuk sekolah ini.</p>
        </div>
    </div>
</x-slot>

<div class="py-8">
    <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">

        {{-- ================================================== --}}
        {{-- 1. SETUP EDUXID CLOUD [G1]                        --}}
        {{-- ================================================== --}}
        <div class="bg-white rounded-2xl shadow-sm border overflow-hidden mb-6 {{ $eduxidStatus['ready'] ? 'border-green-200' : 'border-yellow-300' }}">
            <div class="px-6 py-5 border-b {{ $eduxidStatus['ready'] ? 'bg-green-50 border-green-100' : 'bg-yellow-50 border-yellow-100' }}">
                <h3 class="text-lg font-bold text-gray-900">🔑 Setup eduXid Cloud</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Kode ini (edx_sch_...) diberikan oleh pengembang eduXid khusus untuk sekolah ini (satu sekolah satu kunci).
                    Tempel sekali di sini — seluruh tombol di bawah otomatis memakainya.
                </p>
            </div>

            <div class="p-6">
                {{-- Status koneksi --}}
                <div id="setup-status"
                     class="rounded-xl px-4 py-3 text-sm font-semibold mb-4 {{ $eduxidStatus['ready'] ? 'bg-green-50 text-green-800' : 'bg-yellow-50 text-yellow-800' }}">
                    @if ($eduxidStatus['ready'])
                        ✓ Siap terhubung
                        @if ($eduxidStatus['token_preview'])
                            · kode: <code>{{ $eduxidStatus['token_preview'] }}</code>
                        @endif
                        · sumber: {{ $eduxidStatus['token_source'] }}
                    @else
                        ⏳ Belum terhubung — tempel kode yang diberikan oleh Pengembang eduXid di bawah,
                        lalu jalankan Push Data Pokok, Push Akun Guru &amp; Push Akun Murid di halaman ini.
                    @endif
                </div>

                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">
                    Kode Admin Sekolah (edx_sch_...)
                </label>
                <input type="password" id="eduxid-admin-token"
                       placeholder="Tempel kode — kosongkan jika tidak ingin mengubah"
                       class="w-full rounded-xl border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500 mb-3">

                <button type="button" id="btn-setup-save"
                        class="w-full inline-flex items-center justify-center gap-2 bg-emerald-600 text-white px-5 py-3 rounded-xl hover:bg-emerald-700 transition text-sm font-bold shadow-sm disabled:opacity-50">
                    💾 Simpan Kode Sekolah
                </button>

                {{-- V21R124: Password-encrypted portable backup. Only inside authenticated local Admin School. --}}
                <div id="identity-backup-card" class="mt-5 rounded-xl border-2 border-indigo-300 bg-indigo-50 p-4 text-sm">
                    <h4 class="font-bold text-indigo-950">🔐 Cadangan Identitas Sekolah &amp; Kode Pairing</h4>
                    <p class="mt-2 text-indigo-900">Setelah aktivasi Cloud, <b>simpan satu file terenkripsi (.enc.json)</b>
                       untuk pemulihan saat instal ulang atau pindah komputer. Berkas ini berisi kunci sekolah asli dan kode pairing,
                       tetapi keduanya tidak dapat dibaca tanpa kata sandi pemulihan.</p>
                    <p class="mt-2 font-bold text-amber-900">PENTING: simpan file di USB pribadi/penyimpanan aman,
                       dan catat kata sandi di tempat <u>terpisah</u>. Kata sandi tidak dapat dipulihkan oleh eduXid.
                       Jangan bagikan file dan kata sandi bersamaan.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label for="identity-backup-password" class="block font-semibold text-indigo-950">Kata sandi cadangan (minimal 14 karakter)</label>
                            <input id="identity-backup-password" type="password" autocomplete="new-password" minlength="14" maxlength="256"
                                class="mt-1 block w-full rounded-lg border border-indigo-300 bg-white px-3 py-2" placeholder="Buat kata sandi yang kuat">
                        </div>
                        <div>
                            <label for="identity-backup-confirm" class="block font-semibold text-indigo-950">Ulangi kata sandi cadangan</label>
                            <input id="identity-backup-confirm" type="password" autocomplete="new-password" minlength="14" maxlength="256"
                                class="mt-1 block w-full rounded-lg border border-indigo-300 bg-white px-3 py-2" placeholder="Ulangi kata sandi">
                        </div>
                    </div>
                    <p id="identity-save-as-hint" class="mt-3 text-xs font-semibold text-indigo-900">
                        📁 Tentukan sendiri folder atau USB dan nama file cadangan melalui dialog <b>Simpan Sebagai</b>.
                        Pada browser tanpa dukungan dialog ini, aktifkan pengaturan unduhan untuk selalu menanyakan lokasi penyimpanan.
                    </p>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <button type="button" id="btn-identity-enc-export" class="rounded-lg bg-indigo-700 px-3 py-2 font-bold text-white hover:bg-indigo-800 disabled:opacity-50">
                            📁 Pilih Lokasi &amp; Simpan Cadangan (.enc.json)
                        </button>
                        <button type="button" id="btn-identity-enc-import" class="rounded-lg border border-indigo-400 bg-white px-3 py-2 font-bold text-indigo-800">
                            ♻️ Pulihkan dari File Terenkripsi
                        </button>
                    </div>
                    <div class="mt-2">
                        <label for="identity-backup-pairing-override" class="block text-xs font-semibold text-indigo-900">Kode pairing terbaru (opsional, jika kode dalam file telah dicabut)</label>
                        <input id="identity-backup-pairing-override" type="password" autocomplete="off"
                            class="mt-1 block w-full rounded-lg border border-indigo-300 bg-white px-3 py-2" placeholder="Kosongkan jika pairing dalam file masih aktif">
                    </div>
                    <input id="identity-enc-file" type="file" accept=".json,.enc.json,application/json" class="hidden">
                    <a id="identity-enc-download-ready" class="hidden mt-3 inline-flex w-full items-center justify-center rounded-lg bg-green-700 px-3 py-3 font-bold text-white hover:bg-green-800" aria-label="Unduh file cadangan identitas terenkripsi">
                        ⬇️ Unduh File Cadangan (.enc.json)
                    </a>
                    <div id="identity-enc-msg" class="mt-2 text-xs font-semibold text-indigo-950" aria-live="polite"></div>
                </div>

                <div id="setup-msg" class="hidden mt-4 text-sm font-semibold"></div>
            </div>
        </div>

        {{-- ================================================== --}}
        {{-- 2. PUSH DATA POKOK KE CLOUD [CLOUD-MASTER]        --}}
        {{-- Prasyarat seluruh sinkronisasi lain — diletakkan  --}}
        {{-- sebelum kartu direktori. Route di-guard            --}}
        {{-- Route::has(): kartu hilang rapi bila route belum   --}}
        {{-- terdaftar.                                        --}}
        {{-- ================================================== --}}
        @if (Route::has('admin.pushMasterDataCloud'))
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6 border-t-4 border-cyan-500">
            <div class="px-6 py-5 border-b border-cyan-100 bg-cyan-50">
                <h3 class="text-lg font-bold text-cyan-950">☁️ Push Data Pokok ke Cloud</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Unggah snapshot Data Pokok (sekolah, GTK, murid, rombel, mapel, pembelajaran, kokurikuler)
                    ke eduXid Cloud. <b>Wajib dijalankan setiap kali Data Pokok berubah</b> —
                    laptop guru menariknya lewat "Tarik via Cloud".
                </p>
            </div>

            <div class="p-6">
                <button type="button" id="btn-push-master"
                        class="w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-5 py-3 rounded-xl hover:bg-blue-700 transition text-sm font-bold shadow-sm disabled:opacity-50">
                    ☁️ Push Data Pokok
                </button>
                <div id="push-master-result" class="hidden mt-4 text-sm rounded-xl px-4 py-3"></div>
            </div>
        </div>
        @endif

        {{-- ================================================== --}}
        {{-- 3. PUSH DIREKTORI GURU                            --}}
        {{-- ================================================== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6 border-l-4 border-teal-500">
            <div class="px-6 py-5 border-b border-teal-50">
                <h3 class="font-bold text-gray-800">👨‍🏫 Sinkronisasi Direktori Akun Guru</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Mengirim data akun guru (NIP/NIK, nama, daftar rombel) ke eduXid Cloud agar guru dapat
                    login di <b>eduXid Guru</b> di HP dengan NIP/NIK dan password awal = <b>NPSN sekolah</b>.
                    Jalankan ulang setiap kali data guru berubah.
                    <b>Password yang sudah diganti guru tidak akan ditimpa.</b>
                </p>
            </div>

            <div class="p-6">
                <button type="button" id="btn-push-teachers"
                        class="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-3 rounded-xl hover:bg-indigo-700 transition text-sm font-bold shadow-sm disabled:opacity-50">
                    👨‍🏫 Push Akun Guru
                </button>
                <div id="push-teachers-status" class="mt-3 text-sm text-gray-600"></div>
            </div>
        </div>

        {{-- ================================================== --}}
        {{-- 4. PUSH DIREKTORI SISWA                           --}}
        {{-- ================================================== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="font-bold text-gray-800">👥 Sinkronisasi Direktori Akun Murid</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Mengirim data akun murid (NISN, nama, rombel semester aktif, nama sekolah) ke eduXid Cloud.
                    Jalankan ulang setiap kali data murid/rombel berubah dari Dapodik.
                    <b>Password yang sudah diganti murid tidak akan ditimpa.</b>
                </p>
            </div>

            <div class="p-6">
                <button type="button" id="btn-push-accounts"
                        class="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-3 rounded-xl hover:bg-indigo-700 transition text-sm font-bold shadow-sm disabled:opacity-50">
                    👥 Push Akun Murid
                </button>
                <div id="push-status" class="mt-3 text-sm text-gray-600"></div>
            </div>
        </div>

        {{-- ================================================== --}}
        {{-- 5. BUAT KODE GURU (pairing laptop)                --}}
        {{-- ================================================== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="bg-gradient-to-r from-indigo-50 to-white px-6 py-5 border-b border-indigo-100">
                <h3 class="text-lg font-bold text-indigo-950">🔑 Buat Kode Guru</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Bagikan kode ini kepada guru untuk memasangkan perangkatnya dengan eduXid Cloud.
                    Kode hanya ditampilkan sekali — Cloud menyimpan hash-nya saja.
                </p>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Batas Pemakaian</label>
                        <select id="max-uses" class="w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="1">1 perangkat (sekali pakai)</option>
                            <option value="3">3 perangkat</option>
                            <option value="5">5 perangkat</option>
                            <option value="0" selected>Tanpa batas</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Kedaluwarsa (opsional)</label>
                        <input type="datetime-local" id="expires-at" class="w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                </div>

                <button type="button" id="btn-create" class="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 text-white px-5 py-3 rounded-xl hover:bg-indigo-700 transition text-sm font-bold shadow-sm">
                    🔑 Buat Kode Pairing Guru
                </button>

                <div id="result-box" class="hidden mt-5 rounded-2xl border-2 border-dashed border-green-300 bg-green-50 p-5 text-center">
                    <p class="text-xs font-bold uppercase tracking-wide text-green-700">Kode Guru</p>
                    <p id="code-text" class="text-lg font-mono font-bold text-green-900 break-all mt-2 select-all"></p>
                    <p class="text-xs text-gray-500 mt-2">Salin dan kirimkan ke guru. Kode ini tidak akan ditampilkan lagi.</p>
                    <button type="button" id="btn-copy" class="mt-3 inline-flex items-center gap-2 bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 text-sm font-bold">📋 Salin Kode</button>
                </div>

                <div id="error-box" class="hidden mt-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm font-semibold"></div>
            </div>
        </div>


        {{-- ================================================== --}}
        {{-- 6. RESYNC DEVICE SISWA                            --}}
        {{-- ================================================== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden mb-6">
            <div class="px-6 py-5 border-b border-gray-100">
                <h3 class="font-bold text-gray-800">🔄 Kirim Ulang Konten ke Device Murid</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Untuk murid yang melapor kelas/materi tidak muncul (ganti HP, hapus data browser).
                    Format device ID: <code class="text-xs">NPSN-NISN-HP</code>.
                </p>
            </div>

            <div class="p-6">
                <input type="text" id="resync-device-id" placeholder="contoh: 30102991-0109769101-HP"
                       class="w-full border-gray-300 rounded-md shadow-sm text-sm p-2 mb-3 font-mono">
                <button type="button" id="btn-resync-device"
                        class="inline-flex items-center gap-2 bg-amber-600 text-white px-4 py-2.5 rounded-md hover:bg-amber-700 font-bold text-sm shadow-sm disabled:opacity-50">
                    🔄 Kirim Ulang Konten
                </button>
                <div id="resync-status" class="mt-3 text-sm text-gray-600"></div>
            </div>
        </div>

        {{-- ================================================== --}}
        {{-- 7. PANDUAN                                        --}}
        {{-- ================================================== --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 px-6 py-5">
            <h4 class="text-sm font-bold text-gray-700 mb-2">Setup Awal Sekolah Baru</h4>
            <ol class="text-sm text-gray-600 space-y-1 list-decimal list-inside">
                <li>Terima kunci sekolah (edx_sch_...) dari operator pusat → tempel di kartu <b>Setup Cloud</b> di atas → Simpan.</li>
                <li>Impor Dapodik (NPSN, nama sekolah, murid, guru, penugasan).</li>
                <li>Tekan <b>☁️ Push Data Pokok</b> — laptop guru kini bisa menarik via Cloud.</li>
                <li>Tekan <b>👥 Push Akun Guru</b> dan <b>👨‍🏫 Push Akun Murid</b> untuk akses PWA guru dan murid.</li>
                <li>Murid &amp; guru login di aplikasi eduXid di HP masing-masing — selesai.</li>
            </ol>

            <div class="mt-3 pt-3 border-t border-gray-100">
                <h4 class="text-sm font-bold text-gray-700 mb-2">Alur Pairing Guru (Laptop)</h4>
                <ol class="text-sm text-gray-600 space-y-1 list-decimal list-inside">
                    <li>Buat Kode Guru di halaman ini.</li>
                    <li>Kirim kode ke guru (WhatsApp / langsung).</li>
                    <li>Guru membuka <span class="font-semibold">LMS Guru</span> → kartu "eduXid Cloud" → tempel kode → Hubungkan.</li>
                    <li>Perangkat guru terdaftar; sinkronisasi nilai &amp; konten aktif.</li>
                </ol>
            </div>

            <div class="mt-3 pt-3 border-t border-gray-100">
                <h4 class="text-sm font-bold text-gray-700 mb-2">Alur eduXid Guru (HP)</h4>
                <ol class="text-sm text-gray-600 space-y-1 list-decimal list-inside">
                    <li>Login dengan <span class="font-semibold">NPSN + NIP/NIK + password awal NPSN</span>.</li>
                    <li>Kelas, materi, tugas, submission &amp; hasil CBT muncul otomatis; guru dapat menilai dari HP.</li>
                </ol>
            </div>
        </div>

    </div>
</div>

@push('scripts')
<script>
(function () {
    'use strict';

    /*
     * ============================================================
     * HELPER — POST JSON tunggal untuk seluruh tombol di halaman
     * (dedup pola fetch yang semula diulang di setiap handler).
     * ============================================================
     */
    async function postJson(url, body) {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });

        const data = await response.json().catch(() => null);

        if (!response.ok || !data || data.success !== true) {
            throw new Error((data && data.message) || ('HTTP ' + response.status));
        }

        return data;
    }

    function escHtml(v) {
        return String(v ?? '')
            .replaceAll('&', '&amp;').replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    }

    /* ============================================================
     * SETUP CLOUD [G1] — simpan kunci sekolah ke database lokal.
     * URL Cloud dikelola internal aplikasi dan tidak diekspos ke UI.
     * ============================================================ */
    const btnSetup = document.getElementById('btn-setup-save');
    if (btnSetup) {
        btnSetup.addEventListener('click', async function () {
            const st = document.getElementById('setup-msg');

            btnSetup.disabled = true;
            btnSetup.textContent = '⏳ Menyimpan...';
            st.classList.remove('hidden');
            st.innerHTML = '<div class="bg-blue-50 text-blue-700 px-4 py-3 rounded-xl">Menyimpan...</div>';

            try {
                const data = await postJson('{{ route('admin.eduxid-cloud.setup') }}', {
                    admin_token: document.getElementById('eduxid-admin-token').value,
                });

                st.innerHTML = '<div class="bg-green-50 text-green-800 px-4 py-3 rounded-xl">✓ ' + escHtml(data.message || 'Tersimpan.') + '</div>';
                document.getElementById('eduxid-admin-token').value = '';
                showBackupMessage('✅ Aktivasi tersimpan. WAJIB SIMPAN CADANGAN SEKARANG: buat kata sandi, lalu klik Pilih Lokasi & Simpan Cadangan (.enc.json). Simpan kata sandinya di tempat terpisah.');
                const backupCard = document.getElementById('identity-backup-card');
                if (backupCard) backupCard.scrollIntoView({behavior:'smooth',block:'center'});
                if (backupPass) backupPass.focus();
            } catch (e) {
                st.innerHTML = '<div class="bg-red-50 text-red-700 px-4 py-3 rounded-xl">✕ ' + escHtml(e.message) + '</div>';
            } finally {
                btnSetup.disabled = false;
                btnSetup.textContent = '💾 Simpan Kunci Sekolah';
            }
        });
    }

    /* V21R124: only encrypted credentials leave PHP, never plaintext credentials. */
    const identityEncMsg = document.getElementById('identity-enc-msg');
    const identityEncRoute = '{{ route('admin.eduxid-cloud.setup') }}';
    const backupPass = document.getElementById('identity-backup-password');
    const backupConfirm = document.getElementById('identity-backup-confirm');
    function showBackupMessage(message) { if (identityEncMsg) identityEncMsg.textContent = message; }
    function backupPassword() {
        const a = backupPass ? backupPass.value : '';
        const b = backupConfirm ? backupConfirm.value : '';
        if (a.length < 14 || a.length > 256 || a !== b) throw new Error('Isi kata sandi minimal 14 karakter dan ulangi dengan tepat.');
        return a;
    }
    function forgetBackupPassword() {
        if (backupPass) backupPass.value = '';
        if (backupConfirm) backupConfirm.value = '';
    }
    // V21R127: transparan untuk browser yang mendukung Save As dan browser fallback.
    // Jangan mengubah endpoint export dan jangan pernah mengunduh secret plaintext.
    let preparedIdentityBackupUrl = null;
    function clearPreparedIdentityBackup() {
        const a = document.getElementById('identity-enc-download-ready');
        if (a) { a.classList.add('hidden'); a.removeAttribute('href'); a.removeAttribute('download'); }
        if (preparedIdentityBackupUrl !== null) {
            URL.revokeObjectURL(preparedIdentityBackupUrl);
            preparedIdentityBackupUrl = null;
        }
    }
    function validatedEncryptedEnvelope(data) {
        if (!/^\d{8}$/.test(String(data.school_id || '')) ||
            !/^eduXid-Identitas-dan-Pairing-\d{8}\.enc\.json$/.test(String(data.filename || '')) ||
            typeof data.encrypted_file !== 'string' || data.encrypted_file.length > 16384) {
            throw new Error('Server tidak menghasilkan paket cadangan terenkripsi yang valid.');
        }
        let meta;
        try { meta = JSON.parse(data.encrypted_file); }
        catch (_) { throw new Error('Respons cadangan bukan JSON terenkripsi yang valid.'); }
        if (!meta || meta.format !== 'EDUXID-SCHOOL-IDENTITY-PAIRING-ENC-V1' ||
            !meta.encryption || meta.encryption.algorithm !== 'AES-256-GCM' ||
            Object.prototype.hasOwnProperty.call(meta, 'KUNCI_IDENTITAS_SEKOLAH') ||
            Object.prototype.hasOwnProperty.call(meta, 'KODE_PAIRING_CLOUD')) {
            throw new Error('Paket bukan cadangan AES-256-GCM yang sah. Penyimpanan dibatalkan.');
        }
        return data;
    }
    async function requestEncryptedBackup(pass) {
        const result = await postJson(identityEncRoute, {
            identity_backup_action: 'export', backup_password: pass
        });
        return validatedEncryptedEnvelope(result.data || {});
    }
    function offerManualEncryptedDownload(data) {
        clearPreparedIdentityBackup();
        const anchor = document.getElementById('identity-enc-download-ready');
        if (!anchor) throw new Error('Kontrol unduh cadangan tidak tersedia. Muat ulang halaman.');
        preparedIdentityBackupUrl = URL.createObjectURL(new Blob([data.encrypted_file], {type: 'application/json'}));
        anchor.href = preparedIdentityBackupUrl;
        anchor.download = data.filename;
        anchor.classList.remove('hidden');
        showBackupMessage('✓ File terenkripsi siap. KLIK TOMBOL "UNDUH FILE CADANGAN" di bawah untuk menyimpannya. Jika browser tidak menanyakan folder, aktifkan pengaturan unduhan "Tanyakan tempat menyimpan setiap file".');
    }
    const readyDownload = document.getElementById('identity-enc-download-ready');
    if (readyDownload) readyDownload.addEventListener('click', function () {
        showBackupMessage('Unduhan diminta ke browser. Pastikan file .enc.json benar-benar ada di folder yang dipilih. Simpan kata sandi secara terpisah.');
        readyDownload.classList.add('hidden');
        // Biarkan URL hidup sebentar agar browser sempat menyelesaikan unduhan.
        const url = preparedIdentityBackupUrl;
        preparedIdentityBackupUrl = null;
        if (url) setTimeout(() => URL.revokeObjectURL(url), 60000);
    });
    window.addEventListener('pagehide', clearPreparedIdentityBackup);
    const exportButton = document.getElementById('btn-identity-enc-export');
    if (exportButton) exportButton.addEventListener('click', async function () {
        if (exportButton.disabled) return;
        clearPreparedIdentityBackup();
        let pass;
        try { pass = backupPassword(); }
        catch (error) { showBackupMessage('Isi kata sandi dan konfirmasi dahulu: ' + error.message); return; }
        let handle = null;
        let pickerStatus = '';
        // API ini hanya tersedia pada origin aman (localhost/HTTPS) dan browser pendukung.
        // Panggil dari event klik, SEBELUM await permintaan ke server.
        if (window.isSecureContext && typeof window.showSaveFilePicker === 'function') {
            try {
                handle = await window.showSaveFilePicker({
                    id: 'eduxid-school-identity-backup',
                    suggestedName: 'eduXid-Identitas-dan-Pairing.enc.json',
                    types: [{description: 'Cadangan identitas eduXid terenkripsi', accept: {'application/json': ['.json']}}],
                    excludeAcceptAllOption: false
                });
            } catch (error) {
                if (error && error.name === 'AbortError') {
                    showBackupMessage('Dibatalkan: pengguna menutup dialog Simpan Sebagai. Tidak ada file dibuat.');
                    forgetBackupPassword();
                    return;
                }
                // NotAllowedError/SecurityError: jangan membuat tombol seolah mati.
                pickerStatus = 'Dialog Simpan Sebagai ditolak browser (' + (error.name || 'error') + '). ';
            }
        } else {
            pickerStatus = 'Dialog Simpan Sebagai tidak tersedia pada browser/alamat ini. ';
        }
        exportButton.disabled = true;
        const label = exportButton.textContent;
        exportButton.textContent = '⏳ Menyiapkan cadangan terenkripsi...';
        showBackupMessage(pickerStatus + 'Menghubungi Admin Sekolah untuk membuat cadangan...');
        try {
            const data = await requestEncryptedBackup(pass);
            if (handle) {
                let writer = null;
                try {
                    writer = await handle.createWritable();
                    await writer.write(data.encrypted_file);
                    await writer.close();
                } catch (error) {
                    if (writer) try { await writer.abort(); } catch (_) {}
                    throw new Error('Gagal menulis file melalui browser: ' + (error.message || String(error)));
                }
                showBackupMessage('✓ Cadangan terenkripsi berhasil disimpan sebagai ' + handle.name + '. Pastikan file dapat ditemukan dan simpan kata sandinya secara terpisah.');
            } else {
                offerManualEncryptedDownload(data);
            }
        } catch (error) {
            clearPreparedIdentityBackup();
            showBackupMessage('✕ Cadangan BELUM disimpan: ' + (error.message || String(error)) + '. Jika halaman diakses melalui IP LAN, coba buka langsung di komputer Admin menggunakan http://127.0.0.1 dengan port aplikasi.');
        } finally {
            forgetBackupPassword();
            exportButton.disabled = false;
            exportButton.textContent = label;
        }
    });
    const importButton=document.getElementById('btn-identity-enc-import');
    const importFile=document.getElementById('identity-enc-file');
    if (importButton && importFile) {
        importButton.addEventListener('click',()=>importFile.click());
        importFile.addEventListener('change',async()=>{
            const file=importFile.files && importFile.files[0];importFile.value='';
            if (!file) return;
            if (file.size>16384 || !file.name.toLowerCase().endsWith('.enc.json')) {
                showBackupMessage('Pilih cadangan .enc.json eduXid (maksimal 16 KB).');return;
            }
            let pass='';
            try { pass=backupPassword(); }
            catch(e){showBackupMessage(e.message+' Isi kedua kolom kata sandi file terlebih dahulu.');return;}
            if (!confirm('Pulihkan HANYA pada Admin Sekolah yang benar. File, NPSN, dan segel Cloud akan diperiksa; data yang sudah ada tidak ditimpa. Lanjutkan?'))return;
            importButton.disabled=true;
            try {
                const encrypted=await file.text();
                const override=document.getElementById('identity-backup-pairing-override');
                const response=await postJson(identityEncRoute,{identity_backup_action:'restore', identity_encrypted_file:encrypted,backup_password:pass,backup_pairing_override:override ? override.value.trim() : ''});
                if (override) override.value='';
                showBackupMessage(response.message || 'Pemulihan berhasil. Tutup lalu jalankan ulang eduXid.exe.');
            } catch(e){showBackupMessage('Pemulihan ditolak: '+e.message);}
            finally {forgetBackupPassword();importButton.disabled=false;}
        });
    }

    /* ============================================================
     * [CLOUD-MASTER] PUSH DATA POKOK
     * ============================================================ */
    const btnMaster = document.getElementById('btn-push-master');
    if (btnMaster) {
        const res = document.getElementById('push-master-result');

        btnMaster.addEventListener('click', async function () {
            if (!confirm('Unggah snapshot Data Pokok ke eduXid Cloud?\n\nProses ini bisa memakan waktu hingga satu menit untuk sekolah besar. Jangan tutup halaman.')) {
                return;
            }

            btnMaster.disabled = true;
            const originalText = btnMaster.innerHTML;
            btnMaster.innerHTML = '⏳ Mengunggah...';

            res.classList.remove('hidden', 'bg-green-50', 'text-green-700', 'bg-red-50', 'text-red-700');
            res.textContent = 'Mengunggah snapshot ke Cloud...';

            try {
                const data = await postJson('{{ route("admin.pushMasterDataCloud") }}', {});

                res.classList.add('bg-green-50', 'text-green-700');
                res.textContent = data.message;

            } catch (e) {
                res.classList.add('bg-red-50', 'text-red-700');
                res.textContent = '✕ ' + escHtml(e.message);
            } finally {
                btnMaster.disabled = false;
                btnMaster.innerHTML = originalText;
            }
        });
    }

    /* ============================================================
     * PUSH DIREKTORI SISWA & GURU
     * ============================================================ */
    async function pushDirectory(buttonId, statusId, route, idleLabel, busyLabel) {
        const btn = document.getElementById(buttonId);
        const st = document.getElementById(statusId);

        btn.disabled = true;
        btn.textContent = busyLabel;
        st.textContent = '';

        try {
            const data = await postJson(route, {});

            const partialWarning = !!data.warning;
            st.innerHTML = data.success
                ? (partialWarning
                    ? '<span class="text-amber-700 font-bold">⚠ ' + escHtml(data.message || 'Perlu pemeriksaan.') + '</span>'
                    : '<span class="text-green-700 font-bold">✓ ' + escHtml(data.message || 'Berhasil.') + '</span>')
                : '<span class="text-red-700 font-bold">✕ ' + escHtml(data.message || 'Gagal.') + '</span>';
        } catch (e) {
            st.innerHTML = '<span class="text-red-700 font-bold">✕ Kesalahan jaringan: ' + escHtml(e.message) + '</span>';
        } finally {
            btn.disabled = false;
            btn.textContent = idleLabel;
        }
    }

    const btnAccounts = document.getElementById('btn-push-accounts');
    if (btnAccounts) {
        btnAccounts.addEventListener('click', function () {
            pushDirectory('btn-push-accounts', 'push-status', '{{ route('admin.eduxid.studentAccounts.push') }}', '👥 Push Data Murid ke Cloud', '⏳ Mengirim data murid ke Cloud... (bisa 1–2 menit)');
        });
    }

    const btnTeachers = document.getElementById('btn-push-teachers');
    if (btnTeachers) {
        btnTeachers.addEventListener('click', function () {
            pushDirectory('btn-push-teachers', 'push-teachers-status', '{{ route('admin.eduxid.teacherAccounts.push') }}', '👨‍🏫 Push Guru ke Cloud', '⏳ Mengirim direktori guru...');
        });
    }

    /* ============================================================
     * RESYNC DEVICE SISWA
     * ============================================================ */
    const btnResync = document.getElementById('btn-resync-device');
    if (btnResync) {
        btnResync.addEventListener('click', async function () {
            const st = document.getElementById('resync-status');
            const deviceId = document.getElementById('resync-device-id').value.trim();

            if (!deviceId) {
                st.innerHTML = '<span class="text-red-700 font-bold">✕ Device ID wajib diisi.</span>';
                return;
            }

            btnResync.disabled = true;
            btnResync.textContent = '⏳ Memproses...';
            st.textContent = '';

            try {
                const data = await postJson('{{ route('admin.eduxid.device.resync') }}', { device_id: deviceId });

                st.innerHTML = '<span class="text-green-700 font-bold">✓ ' + escHtml(data.message || 'Berhasil.') + '</span>';
            } catch (e) {
                st.innerHTML = '<span class="text-red-700 font-bold">✕ ' + escHtml(e.message) + '</span>';
            } finally {
                btnResync.disabled = false;
                btnResync.textContent = '🔄 Kirim Ulang Konten';
            }
        });
    }

    /* ============================================================
     * BUAT KODE GURU
     * ============================================================ */
    const btnCreate = document.getElementById('btn-create');
    if (btnCreate) {
        const resultBox = document.getElementById('result-box');
        const codeText = document.getElementById('code-text');
        const errorBox = document.getElementById('error-box');
        const btnCopy = document.getElementById('btn-copy');

        btnCreate.addEventListener('click', async function () {
            btnCreate.disabled = true;
            btnCreate.textContent = '⏳ Membuat kode...';
            resultBox.classList.add('hidden');
            errorBox.classList.add('hidden');

            try {
                const data = await postJson('{{ route("admin.eduxid.enrollment.create") }}', {
                    max_uses: Number(document.getElementById('max-uses').value),
                    expires_at: document.getElementById('expires-at').value || null,
                });

                codeText.textContent = data.enrollment_code;
                resultBox.classList.remove('hidden');
            } catch (error) {
                errorBox.textContent = '⚠️ ' + error.message;
                errorBox.classList.remove('hidden');
            } finally {
                btnCreate.disabled = false;
                btnCreate.textContent = '🔑 Generate Kode Guru';
            }
        });

        btnCopy.addEventListener('click', function () {
            navigator.clipboard.writeText(codeText.textContent).then(function () {
                btnCopy.textContent = '✓ Tersalin';
                setTimeout(function () { btnCopy.textContent = '📋 Salin Kode'; }, 2000);
            });
        });
    }
})();
</script>
@endpush

</x-app-layout>