<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.getElementById('cloud-pair-form');
    const btn = document.getElementById('btn-cloud-pair');
    const input = document.getElementById('cloud-pair-code');
    const msg = document.getElementById('cloud-pair-msg');
    const result = document.getElementById('cloud-pair-result');

    if (!form || !btn || !input || !msg) {
        console.warn('[eduXid] Elemen pairing Cloud tidak ditemukan.');
        return;
    }

    /*
     * Route guard — gunakan lms.cloud.pair (atau cloud.pair).
     * Keduanya terdaftar di routes/web.php (satu URI, dua nama).
     */
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
            showMsg('⚠️ Endpoint pairing tidak tersedia. Route cloud.pair / lms.cloud.pair tidak ditemukan.', 'err');
            return;
        }

        if (!code) {
            showMsg('⚠️ Kode Guru wajib diisi. Minta Admin sekolah membuat Kode Guru di panel eduXid Cloud.', 'err');
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

            if (result) {
                result.classList.remove('hidden', 'bg-red-50', 'text-red-700');
                result.classList.add('bg-green-50', 'text-green-700');
                result.textContent = '✓ Pairing berhasil. Halaman akan dimuat ulang...';
            }

            setTimeout(function () { window.location.reload(); }, 1500);
        } catch (error) {
            console.error('[eduXid] Pairing error:', error);
            showMsg('⚠️ ' + escHtml(error.message), 'err');
            btn.disabled = false;
            btn.textContent = '🔗 Hubungkan';
        }
    });
});
</script>