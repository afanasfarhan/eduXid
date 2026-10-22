<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Donasi eduXid</h2>
            <p class="mt-1 text-sm text-gray-500">Dukungan sukarela untuk pengembangan eduXid. Semua fitur tetap gratis.</p>
        </div>
    </x-slot>

    @php
        $eduxidDonationGtk = false;
        if (auth()->check()) {
            try {
                $eduxidDonationGtk = app(\App\Services\DonationStatusService::class)->isGtk(auth()->user());
            } catch (\Throwable) {
                $eduxidDonationGtk = false;
            }
        }
    @endphp

    <style>
        .donation-page-card{transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
        .marquee-donatur-text{animation:marquee-scroll 48s linear infinite}
        @keyframes marquee-scroll{0%{transform:translateX(100%)}100%{transform:translateX(-100%)}}
        .donation-page-card:hover{transform:translateY(-3px);box-shadow:0 18px 42px rgba(15,23,42,.09);border-color:#cbd5e1}
        .donation-status-dot{width:10px;height:10px;border-radius:999px;background:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,.13)}
        .donation-status-dot.ok{background:#10b981;box-shadow:0 0 0 4px rgba(16,185,129,.14)}
        .donation-status-dot.warn{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.14)}
        .donation-status-dot.error{background:#f43f5e;box-shadow:0 0 0 4px rgba(244,63,94,.14)}
        .donation-field{width:100%;border:1px solid #cbd5e1;border-radius:.75rem;background:#fff;padding:.7rem .8rem;font-size:.875rem;color:#0f172a;outline:none}
        .donation-field:focus{border-color:#818cf8;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
        .donation-btn{min-height:42px;display:inline-flex;align-items:center;justify-content:center;padding:.65rem 1rem;border-radius:.7rem;font-size:.82rem;font-weight:800;transition:.18s ease;text-decoration:none;cursor:pointer}
        .donation-btn:hover{transform:translateY(-1px)}.donation-btn:disabled{opacity:.6;cursor:not-allowed;transform:none}
        @media(prefers-reduced-motion:reduce){.donation-page-card,.donation-btn{transition:none}.donation-page-card:hover,.donation-btn:hover{transform:none}}
    </style>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="text-center">
                <img src="{{ asset('img/eduxid-vertical.png') }}" alt="eduXid Logo" class="w-48 h-auto mx-auto mb-4" onerror="this.style.display='none'">
            </div>
            <section class="donation-page-card bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="bg-gradient-to-br from-indigo-50 via-white to-emerald-50 px-6 py-7 sm:px-8">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                        <div class="max-w-2xl">
                            <div class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700">❤️ Donasi Sukarela</div>
                            <h3 class="mt-3 text-2xl font-black text-slate-900">Terima kasih telah menggunakan eduXid</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">Jika eduXid membantu pekerjaan Anda, dukungan seikhlasnya membantu pengembangan fitur, keamanan, dan layanan eduXid Cloud tetap terawat.</p>
                        </div>
                        <div class="rounded-2xl border border-emerald-200 bg-white px-5 py-4 text-sm text-emerald-800 shadow-sm lg:max-w-sm">
                            <strong class="block">Semua fitur eduXid tetap gratis.</strong>
                            <span class="mt-1 block text-xs leading-5 text-emerald-700">Donasi tidak membuka fitur premium dan tidak mengunci akses. Verifikasi donasi hanya menghilangkan jendela Donation Reminder.</span>
                        </div>
                    </div>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2">
                <div class="donation-page-card rounded-2xl border border-blue-200 bg-blue-50 p-6 shadow-sm">
                    <p class="text-xs font-black uppercase tracking-wider text-blue-600">GoPay</p>
                    <p class="mt-2 text-2xl font-black tracking-wide text-slate-900">085705679919</p>
                    <p class="mt-1 text-sm font-semibold text-slate-500">a.n. Anas Farhan</p>
                </div>
                <div class="donation-page-card rounded-2xl border border-purple-200 bg-purple-50 p-6 shadow-sm">
                    <p class="text-xs font-black uppercase tracking-wider text-purple-600">DANA</p>
                    <p class="mt-2 text-2xl font-black tracking-wide text-slate-900">085705679919</p>
                    <p class="mt-1 text-sm font-semibold text-slate-500">a.n. Anas Farhan</p>
                </div>
            </section>

            @if($eduxidDonationGtk)
                <section class="donation-page-card rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" id="eduxidDonationCenter">
                    <div class="flex flex-col gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-black text-slate-900">Status & Verifikasi Donasi</h3>
                            <p class="mt-1 text-sm text-slate-500">Status akun diverifikasi langsung melalui eduXid Cloud.</p>
                        </div>
                        <button type="button" id="donationPageCheck" class="donation-btn border border-slate-200 bg-white text-slate-700 hover:bg-slate-50">Periksa Status</button>
                    </div>

                    <div class="mt-5 flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3" id="donationPageStatusBox">
                        <span class="donation-status-dot mt-1" id="donationPageStatusDot"></span>
                        <div>
                            <p class="text-sm font-bold text-slate-800" id="donationPageStatusTitle">Memeriksa status Cloud…</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500" id="donationPageStatusText">Mohon tunggu.</p>
                        </div>
                    </div>

                    <div id="donationPageVerified" class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-5" hidden>
                        <div class="flex gap-3">
                            <div class="text-2xl">✅</div>
                            <div>
                                <h4 class="font-black text-emerald-900">Donasi akun ini sudah terverifikasi</h4>
                                <p class="mt-1 text-sm leading-6 text-emerald-700">Terima kasih atas dukungan Anda. Donation Reminder tidak akan ditampilkan lagi selama status Cloud tetap terverifikasi.</p>
                            </div>
                        </div>
                    </div>

                    <div id="donationPageForms" class="mt-6 grid gap-6 lg:grid-cols-2">
                        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-5">
                            <h4 class="font-black text-slate-900">Kirim Klaim Donasi</h4>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Setelah klaim dikirim, data otomatis muncul di Donation Admin Pusat.</p>

                            <label class="mt-4 block text-xs font-bold text-slate-600">Nomor WhatsApp Anda
                                <input id="donationPageWhatsapp" class="donation-field mt-1.5" type="tel" maxlength="24" inputmode="tel" autocomplete="tel" placeholder="Contoh: 085212345678">
                            </label>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <label class="block text-xs font-bold text-slate-600">Metode
                                    <select id="donationPageChannel" class="donation-field mt-1.5">
                                        <option value="gopay">GoPay</option>
                                        <option value="dana">DANA</option>
                                    </select>
                                </label>
                                <label class="block text-xs font-bold text-slate-600">Nominal (opsional)
                                    <input id="donationPageAmount" class="donation-field mt-1.5" type="number" min="1000" step="1000" placeholder="Contoh: 50000">
                                </label>
                            </div>

                            <label class="mt-3 block text-xs font-bold text-slate-600">Referensi transaksi
                                <input id="donationPageRef" class="donation-field mt-1.5" type="text" maxlength="120" placeholder="ID / nomor referensi transaksi">
                            </label>

                            <div class="mt-3 flex flex-col gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <strong class="block text-xs text-green-800">Konfirmasi & kode verifikasi</strong>
                                    <span class="mt-1 block text-xs text-green-700">WhatsApp Admin Pusat: 082253181899</span>
                                </div>
                                <a id="donationPageAdminWa" href="https://wa.me/628225318189" target="_blank" rel="noopener noreferrer" class="donation-btn bg-green-600 text-white hover:bg-green-700">Hubungi WhatsApp</a>
                            </div>

                            <div class="mt-4 flex flex-wrap gap-2">
                                <button type="button" id="donationPageClaim" class="donation-btn bg-indigo-600 text-white hover:bg-indigo-700">Kirim Klaim</button>
                                <a id="donationPageClaimWa" href="https://wa.me/628225318189" target="_blank" rel="noopener noreferrer" class="donation-btn border border-green-200 bg-green-50 text-green-700 hover:bg-green-100" hidden>Konfirmasi via WhatsApp</a>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5">
                            <h4 class="font-black text-slate-900">Masukkan Key Verifikasi</h4>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Masukkan kode yang diberikan Admin Pusat melalui WhatsApp. Key hanya berlaku sekali dan terikat ke akun Anda.</p>
                            <label class="mt-4 block text-xs font-bold text-slate-600">Key Verifikasi
                                <input id="donationPageKey" class="donation-field mt-1.5 font-mono" type="text" maxlength="96" autocomplete="off" spellcheck="false" placeholder="EDX-DON-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX">
                            </label>
                            <button type="button" id="donationPageRedeem" class="donation-btn mt-4 bg-amber-500 text-white hover:bg-amber-600">Verifikasi Key</button>
                            <p class="mt-4 text-xs leading-5 text-slate-500">Setelah Cloud menyatakan akun terverifikasi, halaman ini dan Donation Reminder menggunakan status yang sama. Reminder akan berhenti muncul otomatis.</p>
                        </div>
                    </div>
                </section>
            @else
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="font-black text-slate-900">Verifikasi Donasi GTK</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-500">Form klaim dan verifikasi Cloud tersedia untuk akun GTK. Informasi donasi di atas tetap dapat digunakan secara sukarela oleh pengguna lain.</p>
                </section>
            @endif

            <div class="marquee-donatur overflow-hidden whitespace-nowrap rounded-lg border border-green-300 bg-green-50">
                <div class="marquee-donatur-text inline-block py-3 font-semibold text-green-700">
                    ❤️ Terima kasih kepada semua pihak yang telah mendukung pengembangan eduXid untuk pendidikan Indonesia. ❤️
                </div>
            </div>

            <!-- Tentang dan Donasi -->
            <div class="bg-white rounded-xl shadow-sm p-8 mb-8 border-t-4 border-green-500">
                <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    <span class="mr-2">❤️</span> Tentang eduXid
                </h3>

                <p class="text-gray-600 leading-relaxed text-justify mb-4">
                    <strong>eduXid — Smart School Management</strong> adalah platform manajemen sekolah dan pembelajaran digital
                    yang dikembangkan untuk membantu sekolah mengelola kegiatan akademik, administrasi, pembelajaran,
                    penilaian, ujian, dan layanan sekolah dalam satu sistem yang terintegrasi.
                </p>

                <p class="text-gray-600 leading-relaxed text-justify mb-4">
                    eduXid dirancang dengan konsep <strong>offline-first</strong>, sehingga berbagai aktivitas utama sekolah
                    tetap dapat berjalan melalui komputer lokal meskipun koneksi internet terbatas. Ketika internet tersedia,
                    eduXid dapat memanfaatkan <strong>eduXid Cloud</strong> untuk membantu proses sinkronisasi antara komputer
                    sekolah atau guru dengan aplikasi guru dan murid.
                </p>

                <p class="text-gray-600 leading-relaxed text-justify mb-6">
                    Tujuan pengembangan eduXid adalah membantu pekerjaan sekolah menjadi lebih sederhana, terorganisasi,
                    efisien, dan tidak bergantung sepenuhnya pada koneksi internet. Data yang telah dikelola dapat digunakan
                    kembali oleh berbagai bagian sistem sehingga pekerjaan administrasi yang berulang dapat dikurangi dan
                    guru dapat lebih fokus pada proses pembelajaran serta perkembangan murid.
                </p>

                <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-6 mb-6">
                    <h4 class="text-lg font-bold text-indigo-800 mb-4">Kegunaan dan Fitur Utama eduXid</h4>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">🏫 Manajemen Sekolah</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Mengelola data sekolah, guru dan tenaga kependidikan, murid, kelas/rombel,
                                mata pelajaran, pembelajaran, serta pengaturan akademik sekolah.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">📚 Learning Management System</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Guru dapat membuat kelas pembelajaran, topik, materi dan tugas, serta membagikan
                                sumber belajar berupa teks, dokumen, gambar, video, audio, tautan, dan file.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">📝 Absensi & Buku Nilai</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Mencatat kehadiran, aktivitas pembelajaran, penilaian diagnostik, formatif,
                                sumatif, catatan murid, dan pengolahan nilai akhir.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">💻 CBT & Bank Soal</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Membantu guru mengelola bank soal, menyusun ujian berbasis komputer,
                                mengatur pelaksanaan ujian, serta mengelola hasil pengerjaan murid.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">📊 Penilaian & Rapor</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Mengintegrasikan hasil penilaian dengan pengolahan nilai akhir dan proses
                                penyusunan rapor sehingga mengurangi pemindahan data secara berulang.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">👨‍🏫 Wali Kelas & BK</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Mendukung wali kelas dan guru BK dalam melakukan pencatatan, pemantauan,
                                serta pengelolaan informasi perkembangan murid.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">🎯 Kokurikuler & Ekstrakurikuler</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Membantu pengelolaan kegiatan kokurikuler dan ekstrakurikuler beserta
                                aktivitas dan penilaiannya.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">📄 Administrasi Sekolah</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Mendukung kebutuhan tata usaha seperti pengelolaan surat, arsip,
                                dokumen sekolah, dan pembuatan kartu pelajar.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">📱 PWA Guru & Murid</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Guru dan murid dapat menggunakan aplikasi berbasis PWA melalui perangkat
                                masing-masing dengan dukungan penggunaan offline dan sinkronisasi saat jaringan tersedia.
                            </p>
                        </div>

                        <div class="bg-white rounded-lg p-4 border border-indigo-100">
                            <h5 class="font-bold text-gray-800 mb-1">☁️ eduXid Cloud Sync</h5>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                Menghubungkan perangkat guru dan murid untuk meneruskan sinkronisasi data
                                sesuai hak akses tanpa menjadikan Cloud sebagai satu-satunya tempat sistem bergantung.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-green-50 border border-green-200 rounded-xl p-6 mb-6">
                    <h4 class="text-lg font-bold text-green-800 mb-3">Mengapa eduXid Dikembangkan?</h4>
                    <p class="text-gray-600 leading-relaxed text-justify">
                        Digitalisasi sekolah seharusnya tidak membuat sekolah semakin bergantung pada banyak aplikasi,
                        koneksi internet yang selalu tersedia, ataupun proses administrasi yang berulang. Karena itu,
                        eduXid terus dikembangkan dengan menempatkan kebutuhan nyata sekolah, kemudahan penggunaan,
                        kemandirian data, kemampuan bekerja secara offline, dan integrasi antarfitur sebagai bagian
                        penting dari pengembangannya.
                    </p>
                </div>
            </div>



            <div class="bg-white rounded-xl shadow-sm p-8 border border-gray-100">
                <h3 class="text-lg font-bold text-gray-800 mb-2">Informasi Lisensi</h3>
                <p class="text-sm text-gray-500">
                    eduXid Core (v{{ config('eduxid.version') }}) - Open Core Model.
                    Dilarang keras memperjualbelikan dan menyalahgunakan aplikasi ini.
                    Diperbolehkan untuk digunakan dan disebarluaskan secara gratis
                    untuk perkembangan dunia pendidikan di Indonesia.
                </p>
            </div>

        </div>
    </div>

    @if($eduxidDonationGtk)
    <script>
    (() => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const urls = {
            status: @json(route('donasi.status')),
            claim: @json(route('donasi.claim')),
            redeem: @json(route('donasi.redeem')),
        };
        let verified = false;
        let reminderDisabled = false;
        let pending = false;
        let poller = null;
        const signalKey = 'eduxid-donation-cloud-recheck';

        const el = (id) => document.getElementById(id);
        const statusBox = el('donationPageStatusBox');
        const statusDot = el('donationPageStatusDot');
        const statusTitle = el('donationPageStatusTitle');
        const statusText = el('donationPageStatusText');
        const forms = el('donationPageForms');
        const verifiedBox = el('donationPageVerified');

        const signalOtherTabs = () => {
            try { localStorage.setItem(signalKey, String(Date.now())); } catch (_) {}
            window.dispatchEvent(new CustomEvent('eduxid:donation-cloud-recheck'));
        };

        const setStatus = (title, text, kind = '') => {
            statusTitle.textContent = title;
            statusText.textContent = text;
            statusDot.className = 'donation-status-dot mt-1' + (kind ? ' ' + kind : '');
            statusBox.className = 'mt-5 flex items-start gap-3 rounded-xl border px-4 py-3 ' + (
                kind === 'ok' ? 'border-emerald-200 bg-emerald-50' :
                kind === 'warn' ? 'border-amber-200 bg-amber-50' :
                kind === 'error' ? 'border-rose-200 bg-rose-50' :
                'border-slate-200 bg-slate-50'
            );
        };

        const jsonFetch = async (url, options = {}) => {
            const response = await fetch(url, {
                credentials: 'same-origin', cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    ...(options.body ? {'Content-Type':'application/json','X-CSRF-TOKEN':csrf} : {}),
                    ...(options.headers || {}),
                },
                ...options,
            });
            let data = {};
            try { data = await response.json(); } catch (_) {}
            return {response, data};
        };

        const applyStatus = (payload, reachable = true) => {
            const info = payload?.data || payload || {};
            const status = String(info.status || 'unverified').toLowerCase();
            const wasSuppressed = verified || reminderDisabled;
            verified = info.verified === true || status === 'verified';
            if (typeof info.reminder_disabled === 'boolean') {
                reminderDisabled = info.reminder_disabled;
            }
            pending = status === 'pending';

            if (verified) {
                setStatus('Terverifikasi melalui eduXid Cloud', 'Terima kasih atas dukungan Anda. Donation Reminder sudah dinonaktifkan untuk akun ini.', 'ok');
                forms.hidden = true;
                verifiedBox.hidden = false;
                if (poller) clearInterval(poller);
                if (!wasSuppressed) signalOtherTabs();
                return true;
            }

            forms.hidden = false;
            verifiedBox.hidden = true;
            if (reminderDisabled) {
                // Penonaktifan pengingat bukan bukti pembayaran dan bukan verifikasi.
                // Form donasi tetap tersedia secara sukarela.
                setStatus('Pengingat donasi dinonaktifkan',
                    pending
                        ? 'Admin Pusat telah menonaktifkan pengingat untuk akun ini. Klaim donasi masih menunggu verifikasi; seluruh fitur tetap gratis.'
                        : 'Admin Pusat telah menonaktifkan pengingat donasi untuk akun ini. Status donasi belum terverifikasi dan donasi tetap sukarela.',
                    'ok');
                if (!wasSuppressed) signalOtherTabs();
                return true;
            }
            if (!reachable) {
                setStatus('Cloud belum dapat dihubungi', 'Status donasi belum dapat diverifikasi. Semua fitur eduXid tetap dapat digunakan gratis.', 'warn');
            } else if (pending) {
                setStatus('Klaim menunggu verifikasi', 'Klaim Anda sudah masuk. Hubungi WhatsApp Admin Pusat 082253181899 untuk konfirmasi dan menerima kode verifikasi.', 'warn');
            } else if (status === 'rejected') {
                setStatus('Klaim belum dapat diverifikasi', 'Periksa referensi transaksi atau hubungi Admin Pusat untuk bantuan.', 'error');
            } else if (status === 'revoked') {
                setStatus('Status verifikasi telah dihapus', 'Anda dapat mengirim klaim donasi kembali. Semua fitur eduXid tetap dapat digunakan gratis.', 'warn');
            } else {
                setStatus('Belum terverifikasi', 'Donasi bersifat sukarela. Verifikasi hanya menghilangkan Donation Reminder; seluruh fitur tetap gratis.', '');
            }
            return false;
        };

        const checkStatus = async () => {
            try {
                const {response, data} = await jsonFetch(urls.status);
                if (!response.ok) return applyStatus(data, false);
                return applyStatus(data, true);
            } catch (_) {
                return applyStatus({}, false);
            }
        };

        el('donationPageCheck')?.addEventListener('click', checkStatus);

        el('donationPageClaim')?.addEventListener('click', async () => {
            const whatsapp = (el('donationPageWhatsapp')?.value || '').trim();
            const waDigits = whatsapp.replace(/\D/g, '');
            const ref = (el('donationPageRef')?.value || '').trim();
            if (waDigits.length < 10 || waDigits.length > 15) {
                setStatus('WhatsApp belum valid', 'Masukkan nomor WhatsApp aktif Anda.', 'error');
                el('donationPageWhatsapp')?.focus(); return;
            }
            if (ref.length < 4) {
                setStatus('Referensi transaksi belum valid', 'Masukkan ID atau nomor referensi transaksi.', 'error');
                el('donationPageRef')?.focus(); return;
            }
            const button = el('donationPageClaim');
            button.disabled = true;
            setStatus('Mengirim klaim…', 'Menghubungkan ke eduXid Cloud.');
            try {
                const amountRaw = el('donationPageAmount')?.value || '';
                const payload = {
                    donor_name: @json(auth()->user()?->name ?? ''),
                    whatsapp,
                    channel: el('donationPageChannel')?.value || 'gopay',
                    transaction_ref: ref,
                    amount: amountRaw === '' ? null : Number(amountRaw),
                };
                const {response, data} = await jsonFetch(urls.claim, {method:'POST', body:JSON.stringify(payload)});
                if (!response.ok || data?.success !== true) throw new Error(data?.message || 'Klaim belum dapat dikirim.');
                const claimId = data?.data?.claim_id || '';
                const message = `Halo Admin eduXid, saya ${@json(auth()->user()?->name ?? '')} sudah mengirim klaim donasi${claimId ? ` (klaim ${claimId})` : ''}. Nomor WhatsApp saya ${whatsapp}. Mohon konfirmasi dan kirimkan kode verifikasi.`;
                const href = `https://wa.me/628225318189?text=${encodeURIComponent(message)}`;
                el('donationPageClaimWa').href = href;
                el('donationPageClaimWa').hidden = false;
                el('donationPageAdminWa').href = href;
                const claimStatus = String(data?.data?.status || 'unverified').toLowerCase();
                // Respons klaim tidak selalu memuat flag penonaktifan pengingat.
                // Minta ulang status akun yang otoritatif dari Cloud.
                await checkStatus();
            } catch (error) {
                setStatus('Klaim belum terkirim', error?.message || 'Klaim belum dapat dikirim ke Cloud.', 'error');
            } finally { button.disabled = false; }
        });

        el('donationPageRedeem')?.addEventListener('click', async () => {
            const code = (el('donationPageKey')?.value || '').trim();
            if (code.length < 20) {
                setStatus('Key belum lengkap', 'Masukkan Key Verifikasi Donasi yang diberikan Admin Pusat.', 'error');
                el('donationPageKey')?.focus(); return;
            }
            const button = el('donationPageRedeem');
            button.disabled = true;
            setStatus('Memverifikasi key…', 'Memeriksa key melalui eduXid Cloud.');
            try {
                const {response, data} = await jsonFetch(urls.redeem, {method:'POST', body:JSON.stringify({code})});
                if (!response.ok || data?.success !== true) throw new Error(data?.message || 'Key verifikasi tidak valid.');
                await checkStatus();
            } catch (error) {
                setStatus('Verifikasi gagal', error?.message || 'Key tidak valid atau sudah kedaluwarsa.', 'error');
            } finally { button.disabled = false; }
        });

        window.addEventListener('storage', (event) => { if (event.key === signalKey) checkStatus(); });
        window.addEventListener('eduxid:donation-cloud-recheck', checkStatus);

        checkStatus();
        poller = setInterval(() => { if (!verified && !reminderDisabled && pending) checkStatus(); }, 15000);
    })();
    </script>
    @endif
</x-app-layout>
