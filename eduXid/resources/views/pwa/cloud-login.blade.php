<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LMS Murid — eduXid</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>window.EDUXID_CLOUD_URL = '{{ rtrim(config('eduxid.sync_server'), '/') }}';</script>
    <script src="{{ asset('js/lms-cloud-direct.js') }}"></script>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            margin: 0; min-height: 100vh;
            background: linear-gradient(135deg, #312e81 0%, #4338ca 45%, #6366f1 100%);
            display: flex; align-items: center; justify-content: center;
            padding: 24px 16px;
        }
        .shell { width: 100%; max-width: 400px; }
        .logo-wrap { text-align: center; margin-bottom: 20px; }
        .logo-wrap img { height: 44px; width: auto; filter: brightness(0) invert(1); }
        .logo-fallback {
            display: none; color: #fff; font-size: 26px; font-weight: 800; letter-spacing: .5px;
        }
        .card {
            background: #fff; border-radius: 20px; padding: 28px 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,.30);
        }
        .title { text-align: center; margin: 0 0 4px; font-size: 20px; font-weight: 800; color: #1e1b4b; }
        .subtitle { text-align: center; margin: 0 0 22px; font-size: 13px; color: #6b7280; }
        .field { margin-bottom: 14px; }
        .field label { display: block; font-size: 12px; font-weight: 700; color: #4b5563; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .4px; }
        .input-wrap { position: relative; }
        .input-wrap .icon {
            position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
            font-size: 15px; pointer-events: none;
        }
        .input-wrap input {
            width: 100%; padding: 12px 12px 12px 40px;
            border: 1.5px solid #d1d5db; border-radius: 12px;
            font-size: 15px; color: #111827; background: #f9fafb;
            outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .input-wrap input:focus {
            border-color: #6366f1; background: #fff;
            box-shadow: 0 0 0 3px rgba(99,102,241,.15);
        }
        .toggle-pass {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            border: 0; background: none; cursor: pointer; font-size: 16px; padding: 4px;
            color: #9ca3af;
        }
        .btn {
            width: 100%; margin-top: 6px; padding: 13px;
            border: 0; border-radius: 12px; cursor: pointer;
            background: linear-gradient(135deg, #4f46e5, #6366f1);
            color: #fff; font-size: 15px; font-weight: 700;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 6px 16px rgba(79,70,229,.35);
            transition: transform .1s, box-shadow .15s, opacity .15s;
        }
        .btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(79,70,229,.40); }
        .btn:disabled { opacity: .65; cursor: not-allowed; }
        .spinner {
            width: 16px; height: 16px; border: 2px solid rgba(255,255,255,.35);
            border-top-color: #fff; border-radius: 50%;
            animation: spin .7s linear infinite; display: none;
        }
        .btn.loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .msg {
            margin: 14px 0 0; padding: 11px 14px; border-radius: 12px;
            font-size: 13px; line-height: 1.5; display: none; text-align: center;
        }
        .msg.err { display: block; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .msg.info { display: block; background: #eef2ff; border: 1px solid #c7d2fe; color: #4338ca; }
        .msg.ok { display: block; background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
        .footer { text-align: center; margin-top: 18px; font-size: 12px; color: rgba(255,255,255,.75); }
        .offline-dot {
            display: inline-flex; align-items: center; gap: 6px;
            margin: 0 auto 16px; padding: 6px 14px; border-radius: 999px;
            font-size: 12px; font-weight: 600; width: max-content;
            background: #fef3c7; border: 1px solid #fde68a; color: #92400e;
        }
        .copyright { text-align: center; margin-top: 10px; font-size: 12px; font-weight: 600; color: #000; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="logo-wrap">
            <img src="{{ asset('public/img/eduxid-horizontal.png') }}" alt="eduXid"
                 onerror="this.style.display='none'; document.querySelector('.logo-fallback').style.display='block';">
            <div class="logo-fallback">eduXid</div>
        </div>

        <div class="card">
            <h1 class="title">LMS Murid</h1>
            <p class="subtitle">Learning Management System.</p>
            <p class="subtitle">Materi dan Aktivitas Pembelajaran untuk Murid — bisa diakses tanpa internet.</p>

            <div class="offline-dot" id="net-status"><span>🟢</span><span>Terhubung internet</span></div>

            <form id="login-form" autocomplete="on">
                <div class="field">
                    <label for="school_id">NPSN Sekolah</label>
                    <div class="input-wrap">
                        <span class="icon">🏫</span>
                        <input id="school_id" type="text" inputmode="numeric" value="{{ $npsn }}"
                               placeholder="NPSN sekolah" autocomplete="off">
                    </div>
                </div>

                <div class="field">
                    <label for="login_id">NISN</label>
                    <div class="input-wrap">
                        <span class="icon">🎓</span>
                        <input id="login_id" type="text" inputmode="numeric"
                               placeholder="Nomor Induk Murid" autocomplete="username">
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="icon">🔑</span>
                        <input id="password" type="password" placeholder="Password murid"
                               autocomplete="current-password" style="padding-right: 44px;">
                        <button type="button" class="toggle-pass" id="toggle-pass" title="Lihat password">👁</button>
                    </div>
                </div>

                <button type="submit" class="btn" id="btn">
                    <span class="spinner" id="spinner"></span>
                    <span id="btn-label">🎓 Masuk LMS</span>
                </button>

                <div class="msg" id="msg"></div>
            </form>
        </div>

 <div class="copyright">© 2026. Anas Farhan. Eduxid untuk Indonesia.</div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('login-form');
            const btn = document.getElementById('btn');
            const btnLabel = document.getElementById('btn-label');
            const spinner = document.getElementById('spinner');
            const msg = document.getElementById('msg');
            const netStatus = document.getElementById('net-status');

            function setMsg(text, type) {
                msg.textContent = text;
                msg.className = 'msg' + (type ? ' ' + type : '');
            }
            function setLoading(loading) {
                btn.disabled = loading;
                btn.classList.toggle('loading', loading);
                btnLabel.textContent = loading ? 'Memproses...' : '🎓 Masuk LMS';
            }
            function netInfo() {
                if (!navigator.onLine) {
                    netStatus.innerHTML = '<span>📴</span><span>Offline — login butuh internet sekali</span>';
                } else {
                    netStatus.innerHTML = '<span>🟢</span><span>Terhubung internet</span>';
                }
            }
            window.addEventListener('online', netInfo);
            window.addEventListener('offline', netInfo);
            netInfo();

            document.getElementById('toggle-pass').addEventListener('click', function () {
                const pass = document.getElementById('password');
                pass.type = pass.type === 'password' ? 'text' : 'password';
                this.textContent = pass.type === 'password' ? '👁' : '🙈';
            });

            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                const schoolId = document.getElementById('school_id').value.trim();
                const nisn = document.getElementById('login_id').value.trim();
                const password = document.getElementById('password').value;

                if (!schoolId || !nisn || !password) {
                    setMsg('Semua kolom wajib diisi.', 'err');
                    return;
                }
                if (!navigator.onLine) {
                    setMsg('Anda sedang offline. Login pertama kali membutuhkan koneksi internet.', 'err');
                    return;
                }

                setLoading(true);
                setMsg('Memeriksa kredensial ke Cloud...', 'info');
                try {
                    // 1) Login ke EDUXID CLOUD (device_token tersimpan di HP)
                    await window.eduXidCloud.login(schoolId, nisn, password);

                    // 2) Login sesi Laravel (UI halaman dari server guru)
                    setMsg('Menyiapkan sesi sekolah...', 'info');
                    const res = await fetch('/api/pwa/student/login', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ npsn: schoolId, nisn: nisn, password: password }),
                    });
                    if (!res.ok) {
                        throw new Error('Login sekolah gagal (HTTP ' + res.status + '). Periksa password Anda.');
                    }

                    // 3) Sinkronisasi awal (jangan blokir kalau lambat)
                    setMsg('✓ Berhasil! Menyiapkan kelas Anda...', 'ok');
                    await window.eduXidCloud.sync().catch(function () {});

                    window.location.href = '{{ route('siswa.lms.index') }}';
                } catch (err) {
                    setMsg(err.message, 'err');
                    setLoading(false);
                }
            });
        })();
    </script>
</body>
</html>