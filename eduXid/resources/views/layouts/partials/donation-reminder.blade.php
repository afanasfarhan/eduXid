<div id="eduxidDonationModal" class="eduxid-donation-overlay" hidden aria-hidden="true">
    <div class="eduxid-donation-dialog" role="dialog" aria-modal="true" aria-labelledby="eduxidDonationTitle">
        <button type="button" class="eduxid-donation-close" data-donation-close aria-label="Tutup">&times;</button>

        <div class="eduxid-donation-icon" aria-hidden="true">❤️</div>
        <h2 id="eduxidDonationTitle">Terima kasih telah menggunakan eduXid</h2>
        <p class="eduxid-donation-copy">
            Jika eduXid membantu pekerjaan Anda, dukungan seikhlasnya membantu pengembangan fitur,
            keamanan, dan layanan eduXid Cloud tetap terawat.
        </p>

        <div class="eduxid-donation-free-note">
            <strong>Semua fitur eduXid tetap dapat digunakan sepenuhnya secara gratis.</strong>
            <span>Donasi bersifat sukarela dan hanya menghilangkan jendela pengingat donasi ini.</span>
        </div>

        <div class="eduxid-donation-methods">
            <div><strong>GoPay</strong><span>085705679919</span></div>
            <div><strong>DANA</strong><span>085705679919</span></div>
            <small>a.n. Anas Farhan</small>
        </div>


        <div id="eduxidDonationState" class="eduxid-donation-state">Memeriksa status donasi melalui eduXid Cloud…</div>

        <div class="eduxid-donation-actions">
            <a href="{{ route('about.index') }}" class="eduxid-donation-primary">Lihat Halaman Donasi</a>
            <button type="button" class="eduxid-donation-secondary" data-donation-claim-toggle>Saya Sudah Donasi</button>
            <button type="button" class="eduxid-donation-ghost" data-donation-close>Nanti Saja</button>
        </div>

        <div id="eduxidDonationClaimPanel" class="eduxid-donation-panel" hidden>
            <h3>Kirim klaim donasi</h3>
            <p>Kirim klaim berikut. Klaim akan langsung muncul di layar Admin Pusat. Setelah Admin memverifikasi, kode akan dikirim melalui WhatsApp.</p>
            <label>Nomor WhatsApp Anda
                <input id="eduxidDonationWhatsapp" type="tel" maxlength="24" inputmode="tel" autocomplete="tel" placeholder="Contoh: 085212345678">
            </label>
            <div class="eduxid-donation-grid" style="margin-top:10px">
                <label>Metode
                    <select id="eduxidDonationChannel">
                        <option value="gopay">GoPay</option>
                        <option value="dana">DANA</option>
                    </select>
                </label>
                <label>Nominal (opsional)
                    <input id="eduxidDonationAmount" type="number" min="1000" step="1000" placeholder="Contoh: 50000">
                </label>
            </div>
            <label>Referensi transaksi
                <input id="eduxidDonationRef" type="text" maxlength="120" placeholder="ID / nomor referensi transaksi">
            </label>

            <div class="eduxid-donation-admin-wa">
                <div>
                    <strong>Konfirmasi &amp; kode verifikasi</strong>
                    <span>WhatsApp Admin Pusat: 082253181899</span>
                </div>
                <a id="eduxidDonationAdminWhatsapp" href="https://wa.me/628225318189" target="_blank" rel="noopener noreferrer">Hubungi WhatsApp</a>
            </div>

            <div class="eduxid-donation-panel-actions">
                <button type="button" class="eduxid-donation-primary" id="eduxidDonationClaimSubmit">Kirim Klaim</button>
                <a class="eduxid-donation-secondary" id="eduxidDonationClaimWhatsapp" href="https://wa.me/628225318189" target="_blank" rel="noopener noreferrer" hidden>Konfirmasi via WhatsApp</a>
                <button type="button" class="eduxid-donation-secondary" id="eduxidDonationCheckStatus">Periksa Status</button>
            </div>

            <div class="eduxid-donation-divider"><span>atau</span></div>

            <h3>Masukkan Key Verifikasi</h3>
            <p>Jika Admin Pusat sudah memberikan Key Verifikasi, masukkan di bawah ini. Key hanya berlaku sekali untuk akun Anda.</p>
            <div class="eduxid-donation-key-row">
                <input id="eduxidDonationKey" type="text" maxlength="96" autocomplete="off" spellcheck="false" placeholder="EDX-DON-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX">
                <button type="button" class="eduxid-donation-primary" id="eduxidDonationRedeem">Verifikasi</button>
            </div>
        </div>
    </div>
</div>

<style>
.eduxid-donation-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.58);backdrop-filter:blur(5px)}
.eduxid-donation-overlay[hidden]{display:none!important}.eduxid-donation-dialog{position:relative;width:min(620px,100%);max-height:92vh;overflow:auto;background:#fff;border:1px solid #e2e8f0;border-radius:24px;box-shadow:0 28px 80px rgba(15,23,42,.28);padding:28px}
.eduxid-donation-close{position:absolute;right:16px;top:12px;width:38px;height:38px;border:0;border-radius:999px;background:#f1f5f9;color:#475569;font-size:26px;line-height:1;cursor:pointer}.eduxid-donation-close:hover{background:#e2e8f0;color:#0f172a}
.eduxid-donation-icon{width:54px;height:54px;display:grid;place-items:center;margin:0 auto 12px;border-radius:18px;background:linear-gradient(135deg,#fff1f2,#fef3c7);font-size:26px;box-shadow:0 8px 24px rgba(244,63,94,.12)}
.eduxid-donation-dialog h2{margin:0;text-align:center;color:#0f172a;font-size:22px;font-weight:800}.eduxid-donation-copy{max-width:520px;margin:10px auto 18px;text-align:center;color:#64748b;line-height:1.6;font-size:14px}
.eduxid-donation-free-note{margin:0 auto 14px;padding:12px 14px;border:1px solid #a7f3d0;background:linear-gradient(135deg,#ecfdf5,#f0fdf4);border-radius:14px;text-align:center;color:#166534;font-size:12px;line-height:1.55}.eduxid-donation-free-note strong{display:block;font-size:13px}.eduxid-donation-free-note span{display:block;margin-top:2px;color:#15803d}.eduxid-donation-methods{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px;border:1px solid #dbeafe;background:linear-gradient(135deg,#eff6ff,#f8fafc);border-radius:16px}.eduxid-donation-methods>div{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:12px;color:#334155}.eduxid-donation-methods small{grid-column:1/-1;text-align:center;color:#64748b;font-weight:700}
.eduxid-donation-admin-wa{margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:14px}.eduxid-donation-admin-wa strong{display:block;color:#166534;font-size:12px}.eduxid-donation-admin-wa span{display:block;margin-top:2px;color:#15803d;font-size:12px}.eduxid-donation-admin-wa a{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 12px;border-radius:10px;background:#16a34a;color:#fff;font-size:12px;font-weight:800;text-decoration:none}.eduxid-donation-admin-wa a:hover{background:#15803d}
.eduxid-donation-state{margin:14px 0 0;padding:11px 13px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:13px;line-height:1.5}.eduxid-donation-state.is-ok{background:#ecfdf5;border-color:#a7f3d0;color:#047857}.eduxid-donation-state.is-warn{background:#fffbeb;border-color:#fde68a;color:#92400e}.eduxid-donation-state.is-error{background:#fff1f2;border-color:#fecdd3;color:#be123c}
.eduxid-donation-actions{display:flex;flex-wrap:wrap;justify-content:center;gap:9px;margin-top:18px}.eduxid-donation-primary,.eduxid-donation-secondary,.eduxid-donation-ghost{min-height:42px;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:11px;font-size:13px;font-weight:800;text-decoration:none;cursor:pointer;transition:.18s ease}.eduxid-donation-primary{border:1px solid #4f46e5;background:#4f46e5;color:#fff}.eduxid-donation-primary:hover{background:#4338ca;transform:translateY(-1px)}.eduxid-donation-secondary{border:1px solid #cbd5e1;background:#fff;color:#334155}.eduxid-donation-secondary:hover{background:#f8fafc;transform:translateY(-1px)}.eduxid-donation-ghost{border:1px solid transparent;background:transparent;color:#64748b}.eduxid-donation-ghost:hover{background:#f8fafc;color:#334155}
.eduxid-donation-panel{margin-top:18px;padding:16px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:16px}.eduxid-donation-panel h3{margin:0 0 4px;color:#0f172a;font-size:14px;font-weight:800}.eduxid-donation-panel p{margin:0 0 12px;color:#64748b;font-size:12px;line-height:1.5}.eduxid-donation-panel label{display:block;color:#475569;font-size:12px;font-weight:700}.eduxid-donation-panel input,.eduxid-donation-panel select{width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:10px 11px;color:#0f172a;font-size:13px;outline:none}.eduxid-donation-panel input:focus,.eduxid-donation-panel select:focus{border-color:#818cf8;box-shadow:0 0 0 3px rgba(99,102,241,.12)}.eduxid-donation-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}.eduxid-donation-panel-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.eduxid-donation-divider{display:flex;align-items:center;gap:10px;margin:16px 0;color:#94a3b8;font-size:11px}.eduxid-donation-divider:before,.eduxid-donation-divider:after{content:"";height:1px;flex:1;background:#e2e8f0}.eduxid-donation-key-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}
@media(max-width:640px){.eduxid-donation-dialog{padding:22px 16px;border-radius:20px}.eduxid-donation-admin-wa{align-items:stretch;flex-direction:column}.eduxid-donation-admin-wa a{width:100%}.eduxid-donation-methods,.eduxid-donation-grid{grid-template-columns:1fr}.eduxid-donation-methods small{grid-column:1}.eduxid-donation-key-row{grid-template-columns:1fr}.eduxid-donation-actions>*{flex:1 1 100%}}
@media(prefers-reduced-motion:reduce){.eduxid-donation-primary,.eduxid-donation-secondary{transition:none}}
</style>

<script>
(() => {
    const modal = document.getElementById('eduxidDonationModal');
    if (!modal) return;

    const state = document.getElementById('eduxidDonationState');
    const panel = document.getElementById('eduxidDonationClaimPanel');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const urls = {
        status: @json(route('donasi.status')),
        claim: @json(route('donasi.claim')),
        redeem: @json(route('donasi.redeem')),
    };
    let verified = false;
    let reminderDisabled = false;
    let polling = null;
    const signalKey = 'eduxid-donation-cloud-recheck';

    const setState = (message, kind = '') => {
        state.textContent = message;
        state.className = 'eduxid-donation-state' + (kind ? ' is-' + kind : '');
    };

    const open = () => {
        if (verified || reminderDisabled) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
    };

    const close = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
    };

    const jsonFetch = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                ...(options.body ? {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf} : {}),
                ...(options.headers || {}),
            },
            ...options,
        });
        let data = {};
        try { data = await response.json(); } catch (_) {}
        return {response, data};
    };

    const signalOtherTabs = () => {
        try { localStorage.setItem(signalKey, String(Date.now())); } catch (_) {}
        window.dispatchEvent(new CustomEvent('eduxid:donation-cloud-recheck'));
    };

    const applyStatus = (data, reachable = true) => {
        const info = data?.data || data || {};
        const status = String(info.status || 'unverified').toLowerCase();
        const wasSuppressed = verified || reminderDisabled;
        // Klaim/verifikasi kadang mengembalikan payload parsial tanpa flag reminder.
        // Jangan menimpa status penonaktifan yang sudah dikonfirmasi Cloud.
        verified = info.verified === true || status === 'verified';
        if (typeof info.reminder_disabled === 'boolean') {
            reminderDisabled = info.reminder_disabled;
        }

        if (verified || reminderDisabled) {
            setState(verified
                ? 'Donasi akun ini sudah terverifikasi. Terima kasih atas dukungan Anda.'
                : 'Pengingat donasi telah dinonaktifkan oleh Admin Pusat untuk akun ini.', 'ok');
            if (polling) clearInterval(polling);
            if (!wasSuppressed) signalOtherTabs();
            setTimeout(close, 350);
            return true;
        }

        if (!reachable) {
            setState('eduXid Cloud belum dapat dihubungi untuk memeriksa status. Semua fitur eduXid tetap dapat digunakan gratis.', 'warn');
        } else if (status === 'pending') {
            setState('Klaim sudah masuk ke Admin Pusat. Hubungi WhatsApp 082253181899 untuk konfirmasi dan menerima kode verifikasi.', 'warn');
        } else if (status === 'rejected') {
            setState('Klaim sebelumnya belum dapat diverifikasi. Periksa referensi transaksi atau gunakan Key Verifikasi dari Admin Pusat.', 'error');
        } else if (status === 'revoked') {
            setState('Status verifikasi donasi telah dihapus oleh Admin Pusat. Anda dapat mengirim klaim kembali jika diperlukan.', 'warn');
        } else {
            setState('Status donasi akun ini belum terverifikasi. Semua fitur tetap gratis; verifikasi donasi hanya menghentikan pengingat ini.', '');
        }
        open();
        return false;
    };

    const checkStatus = async () => {
        try {
            const {response, data} = await jsonFetch(urls.status);
            if (!response.ok && data?.code === 'CLOUD_GTK_PAIRING_REQUIRED') {
                setState('Akun GTK ini belum memiliki pairing Cloud aktif. Hubungi Admin Sekolah untuk aktivasi perangkat yang sesuai. Semua fitur tetap gratis.', 'warn');
                open();
                return false;
            }
            if (!response.ok) return applyStatus(data, false);
            return applyStatus(data, true);
        } catch (_) {
            return applyStatus({}, false);
        }
    };

    document.querySelectorAll('[data-donation-close]').forEach(btn => btn.addEventListener('click', close));
    document.querySelector('[data-donation-claim-toggle]')?.addEventListener('click', () => {
        panel.hidden = !panel.hidden;
        if (!panel.hidden) document.getElementById('eduxidDonationRef')?.focus();
    });
    document.getElementById('eduxidDonationCheckStatus')?.addEventListener('click', checkStatus);

    document.getElementById('eduxidDonationClaimSubmit')?.addEventListener('click', async () => {
        const whatsapp = (document.getElementById('eduxidDonationWhatsapp')?.value || '').trim();
        const waDigits = whatsapp.replace(/\D/g, '');
        if (waDigits.length < 10 || waDigits.length > 15) {
            setState('Masukkan nomor WhatsApp aktif Anda.', 'error');
            document.getElementById('eduxidDonationWhatsapp')?.focus();
            return;
        }
        const ref = (document.getElementById('eduxidDonationRef')?.value || '').trim();
        if (ref.length < 4) {
            setState('Masukkan referensi transaksi yang valid.', 'error');
            return;
        }
        const button = document.getElementById('eduxidDonationClaimSubmit');
        button.disabled = true;
        setState('Mengirim klaim ke eduXid Cloud…');
        try {
            const amountRaw = document.getElementById('eduxidDonationAmount')?.value || '';
            const payload = {
                donor_name: @json(auth()->user()?->name ?? ''),
                whatsapp,
                channel: document.getElementById('eduxidDonationChannel')?.value || 'gopay',
                transaction_ref: ref,
                amount: amountRaw === '' ? null : Number(amountRaw),
            };
            const {response, data} = await jsonFetch(urls.claim, {method: 'POST', body: JSON.stringify(payload)});
            if (!response.ok || data?.success !== true) throw new Error(data?.message || 'Klaim belum dapat dikirim.');
            const claimId = data?.data?.claim_id || '';
            const waLink = document.getElementById('eduxidDonationClaimWhatsapp');
            if (waLink) {
                const message = `Halo Admin eduXid, saya ${@json(auth()->user()?->name ?? '')} sudah mengirim klaim donasi${claimId ? ` (klaim ${claimId})` : ''}. Nomor WhatsApp saya ${whatsapp}. Mohon konfirmasi dan kirimkan kode verifikasi.`;
                waLink.href = `https://wa.me/628225318189?text=${encodeURIComponent(message)}`;
                waLink.hidden = false;
            }
            const topWa = document.getElementById('eduxidDonationAdminWhatsapp');
            if (topWa) {
                const message = `Halo Admin eduXid, saya ${@json(auth()->user()?->name ?? '')} sudah mengirim klaim donasi${claimId ? ` (klaim ${claimId})` : ''}. Mohon konfirmasi dan kode verifikasi.`;
                topWa.href = `https://wa.me/628225318189?text=${encodeURIComponent(message)}`;
            }
            const claimStatus = String(data?.data?.status || 'unverified').toLowerCase();
            // Baca ulang status otoritatif agar flag reminder_disabled tidak hilang
            // dari UI ketika respons klaim hanya memuat status klaim.
            await checkStatus();
        } catch (error) {
            setState(error?.message || 'Klaim belum dapat dikirim.', 'error');
        } finally {
            button.disabled = false;
        }
    });

    document.getElementById('eduxidDonationRedeem')?.addEventListener('click', async () => {
        const code = (document.getElementById('eduxidDonationKey')?.value || '').trim();
        if (code.length < 20) {
            setState('Masukkan Key Verifikasi Donasi yang lengkap.', 'error');
            return;
        }
        const button = document.getElementById('eduxidDonationRedeem');
        button.disabled = true;
        setState('Memverifikasi key melalui eduXid Cloud…');
        try {
            const {response, data} = await jsonFetch(urls.redeem, {method: 'POST', body: JSON.stringify({code})});
            if (!response.ok || data?.success !== true) throw new Error(data?.message || 'Key verifikasi tidak valid.');
            applyStatus(data, true);
        } catch (error) {
            setState(error?.message || 'Key verifikasi tidak valid atau sudah kedaluwarsa.', 'error');
        } finally {
            button.disabled = false;
        }
    });

    modal.addEventListener('click', (event) => { if (event.target === modal) close(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
    window.addEventListener('storage', (event) => { if (event.key === signalKey) checkStatus(); });
    window.addEventListener('eduxid:donation-cloud-recheck', checkStatus);

    checkStatus();
    polling = setInterval(() => { if (!modal.hidden && !verified && !reminderDisabled) checkStatus(); }, 15000);
})();
</script>
