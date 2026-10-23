<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ujian CBT — eduXid</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>window.EDUXID_CLOUD_URL = '{{ rtrim(config('eduxid.sync_server'), '/') }}';</script>
    <script src="{{ asset('js/lms-cloud-direct.js') }}"></script>

    <!-- V11a: KaTeX untuk formula pada soal/opsi/hasil -->
    <link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
    <script defer src="{{ asset('katex/katex.min.js') }}"></script>
    <script defer src="{{ asset('katex/contrib/auto-render.min.js') }}"></script>

    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; margin: 0; background: #f3f4f6; color: #1f2937; }
        .topbar {
            position: sticky; top: 0; z-index: 20; background: #312e81; color: #fff;
            padding: 10px 14px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            box-shadow: 0 2px 10px rgba(0,0,0,.25);
        }
        .topbar .back { color: #c7d2fe; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap; }
        .topbar .title { font-weight: 700; font-size: 14px; flex: 1; min-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .timer { font-variant-numeric: tabular-nums; font-weight: 800; font-size: 16px; background: rgba(255,255,255,.12); border-radius: 10px; padding: 6px 12px; }
        .timer.warn { background: #92400e; }
        .timer.crit { background: #b91c1c; animation: pulse 1s infinite; }
        @keyframes pulse { 50% { opacity: .6; } }
        .wrap { max-width: 720px; margin: 14px auto 40px; padding: 0 12px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 18px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .center { text-align: center; padding: 34px 18px; }
        h1 { font-size: 20px; margin: 0 0 6px; color: #1e1b4b; }
        .sub { font-size: 13px; color: #6b7280; margin: 0 0 14px; }
        .badge { display: inline-block; background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; margin: 2px; }
        .navgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(38px, 1fr)); gap: 6px; }
        .navbtn { height: 38px; border-radius: 9px; border: 1.5px solid #d1d5db; background: #fff; font-weight: 700; font-size: 13px; cursor: pointer; }
        .navbtn.active { border-color: #4f46e5; background: #eef2ff; color: #4338ca; }
        .navbtn.filled { background: #4f46e5; border-color: #4f46e5; color: #fff; }
        .qmeta { font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 8px; }
        .qtype-instruction { font-size: 14px; line-height: 1.5; font-weight: 700; margin: 4px 0 10px; color: #334155; }
        .qtype-instruction:empty { display: none; }
        .qtext { font-size: 16px; line-height: 1.7; margin-bottom: 16px; white-space: pre-line; }
        .opt {
            display: flex; gap: 10px; align-items: flex-start; width: 100%; text-align: left;
            border: 1.5px solid #d1d5db; border-radius: 12px; padding: 12px; margin-bottom: 8px;
            background: #fff; cursor: pointer; font-size: 15px; line-height: 1.5;
        }
        .opt .key { font-weight: 800; color: #4f46e5; min-width: 20px; }
        .opt.sel { border-color: #4f46e5; background: #eef2ff; }
        textarea.answer {
            width: 100%; min-height: 120px; border: 1.5px solid #d1d5db; border-radius: 12px;
            padding: 12px; font-size: 15px; font-family: inherit; resize: vertical; outline: none;
        }
        textarea.answer:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.15); }
        .row { display: flex; gap: 8px; justify-content: space-between; margin-top: 14px; }
        .btn { border: 0; border-radius: 12px; padding: 12px 18px; font-size: 14px; font-weight: 700; cursor: pointer; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .btn-ghost { background: #e5e7eb; color: #374151; }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-danger { background: #dc2626; color: #fff; }
        .score-big { font-size: 52px; font-weight: 800; color: #4338ca; margin: 8px 0; }
        .save-note { text-align: right; font-size: 11px; color: #9ca3af; margin-top: 6px; min-height: 14px; }
        .screen { display: none; }
        .screen.on { display: block; }
        ul.perq { list-style: none; padding: 0; margin: 10px 0 0; font-size: 13px; }
        ul.perq li { padding: 5px 0; border-bottom: 1px dashed #e5e7eb; }
    </style>
</head>
<body>

<div class="topbar">
    <a class="back" href="{{ route('siswa.lms.index') }}">← LMS</a>
    <span class="title" id="bar-title">Ujian CBT</span>
    <span class="timer" id="timer" style="display:none;">--:--</span>
    <span class="badge" id="focus-badge" style="display:none; background:rgba(255,255,255,.12); color:#fff; border-color:transparent;" title="Jumlah keluar aplikasi saat ujian">👀 0</span>
</div>

<div class="wrap">

    <!-- LOADING -->
    <div class="card center screen on" id="screen-loading">
        <h1>Memuat ujian…</h1>
        <p class="sub">Membaca paket ujian dari perangkat.</p>
    </div>

    <!-- PAKET TIDAK ADA -->
    <div class="card center screen" id="screen-missing">
        <h1>📦 Paket ujian belum tersedia</h1>
        <p class="sub">Paket ujian belum ada di perangkat ini. Sambungkan internet lalu tarik sinkronisasi.</p>
        <button class="btn btn-primary" id="btn-sync-missing">🔄 Sinkronkan sekarang</button>
        <div class="row" style="justify-content:center;">
            <a class="btn btn-ghost" href="{{ route('siswa.lms.index') }}">← Kembali ke LMS</a>
        </div>
    </div>

    <!-- BELUM AKTIF -->
    <div class="card center screen" id="screen-inactive">
        <h1>⏳ Ujian belum diaktifkan</h1>
        <p class="sub">Guru belum mengaktifkan ujian ini. Paket sudah tersimpan di perangkat; halaman ini otomatis memeriksa ulang saat Anda sinkron.</p>
        <div class="row" style="justify-content:center;">
            <a class="btn btn-ghost" href="{{ route('siswa.lms.index') }}">← Kembali ke LMS</a>
        </div>
    </div>

    <!-- SELESAI / HASIL -->
    <div class="card center screen" id="screen-done">
        <div id="done-body"></div>
        <div class="row" style="justify-content:center; margin-top:16px;">
            <button class="btn btn-ghost" id="btn-refresh-result">🔄 Periksa hasil</button>
            <a class="btn btn-primary" href="{{ route('siswa.lms.index') }}">← Kembali ke LMS</a>
        </div>
    </div>

    <!-- UJIAN -->
    <div class="screen" id="screen-exam">
        <div class="card">
            <div class="navgrid" id="navgrid"></div>
            <p class="save-note" id="save-note">Draft tersimpan otomatis di perangkat.</p>
        </div>
        <div class="card">
            <div class="qmeta" id="qmeta"></div>
            <div id="qtype-instruction" class="qtype-instruction"></div><div class="qtext" id="qtext"></div>
            <div id="qoptions"></div>
            <div class="row">
                <button class="btn btn-ghost" id="btn-prev">← Sebelumnya</button>
                <button class="btn btn-ghost" id="btn-next">Berikutnya →</button>
            </div>
        </div>
        <div class="card center" style="padding: 16px;">
            <button class="btn btn-danger" id="btn-submit" style="width:auto; padding: 12px 26px;">✅ Selesai &amp; Kumpulkan</button>
            <p class="sub" style="margin-top:10px; margin-bottom:0;">Jawaban dikumpulkan <b>sekali</b>. Setelah dikumpulkan, ujian tidak dapat dibuka lagi.</p>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', async function () {

    const EXAM_SYNC_ID = @json($examSyncId);
    const SERVER_LOGIN_ID = String(@json(auth()->user()->login_id ?? null) ?? '').trim();

    const CLOUD = window.eduXidCloud;
    const $ = (id) => document.getElementById(id);

    if (!CLOUD) {
        $('screen-loading').innerHTML = '<h1>⚠️ Client gagal dimuat</h1><p class="sub">Muat ulang halaman.</p>';
        return;
    }

    let exam = null;
    let sess = null;          // sesi ujian lokal (cbt_sessions)
    let questions = [];       // urut sesuai urutan
    let current = 0;
    let timerId = null;
    let autosaveId = null;
    let running = false;

    function show(id) {
        document.querySelectorAll('.screen').forEach(s => s.classList.remove('on'));
        $(id).classList.add('on');
        window.scrollTo({ top: 0 });
    }

    function fmt(ms) {
        const s = Math.max(0, Math.floor(ms / 1000));
        const m = Math.floor(s / 60), ss = s % 60;
        return String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    }

    function optionEntries(q) {
        let o = q.opsi_jawaban;
        if (typeof o === 'string') {
            try { o = JSON.parse(o); } catch (e) { /* opsi lama bukan JSON */ }
        }
        if ((o == null || o === '' || (Array.isArray(o) && o.length === 0) || (typeof o === 'object' && Object.keys(o).length === 0))
                && String(q.jenis_soal ?? '').trim().toLowerCase() === 'benar_salah') {
            // Format kanonik bank soal B=Benar, S=Salah; tampilan dipetakan menjadi A/B.
            return [{ key: 'B', text: 'Benar' }, { key: 'S', text: 'Salah' }];
        }

        if (Array.isArray(o)) {
            return o.map((item, i) => {
                const fallbackKey = String.fromCharCode(65 + i);

                if (item && typeof item === 'object' && !Array.isArray(item)) {
                    return {
                        key: String(item.key ?? fallbackKey).toUpperCase(),
                        text: String(item.text ?? item.label ?? item.value ?? ''),
                    };
                }

                return { key: fallbackKey, text: String(item ?? '') };
            });
        }

        if (o && typeof o === 'object') {
            return Object.entries(o).map(([k, v]) => ({
                key: String(k).toUpperCase(),
                text: String(v ?? ''),
            }));
        }

        return [];
    }

    function isMajemuk(q) {
        return String(q?.jenis_soal ?? '').toLowerCase().includes('majemuk');
    }

    function multipleAnswers(q) {
        const raw = sess?.answers?.[String(q.question_bank_id)];

        if (Array.isArray(raw)) {
            return raw.map(v => String(v).toUpperCase()).filter(Boolean);
        }

        if (typeof raw === 'string' && raw.trim() !== '') {
            const trimmed = raw.trim();
            try {
                const decoded = JSON.parse(trimmed);
                if (Array.isArray(decoded)) {
                    return decoded.map(v => String(v).toUpperCase()).filter(Boolean);
                }
            } catch (e) { /* format lama: lanjut normalisasi */ }

            return trimmed
                .split(/[\s,;|+\/]+/)
                .map(v => v.trim().toUpperCase())
                .filter(Boolean);
        }

        return [];
    }

    // ---------- V11a: KaTeX rendering ----------
    function renderMath(root) {
        if (root && typeof window.renderMathInElement === 'function') {
            try {
                window.renderMathInElement(root, {
                    delimiters: [
                        { left: '$$', right: '$$', display: true },
                        { left: '\\[', right: '\\]', display: true },
                        { left: '\\(', right: '\\)', display: false },
                        { left: '$', right: '$', display: false },
                    ],
                    throwOnError: false,
                    strict: 'ignore',
                    trust: false,
                    ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'],
                    ignoredClasses: ['katex', 'answer'],
                });
            } catch (e) { /* silent */ }
        }
    }

    // ---------- Sesi: cari / buat ----------
    async function findSession(loginId) {
        const all = await CLOUD.dbAll('cbt_sessions');
        const mine = all.filter(s => s && s.exam_sync_id === EXAM_SYNC_ID && String(s.student_login_id ?? '') === loginId);
        const done = mine.find(s => s.status === 'selesai');
        if (done) { return { done, draft: null }; }
        mine.sort((a, b) => Number(b.sync_version ?? 0) - Number(a.sync_version ?? 0));
        return { done: null, draft: mine[0] || null };
    }

    function newSession(loginId) {
        const now = new Date().toISOString();
        const rid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
        return {
            sync_id: rid(),
            entity: 'cbt_answer',
            exam_sync_id: EXAM_SYNC_ID,
            student_login_id: loginId,
            answers: {},
            waktu_mulai: now,
            waktu_submit: null,
            status: 'berlangsung',
            focus_lost_count: 0,
            sync_version: 1,
            sync_status: 'local',
            created_at: now,
            updated_at: now,
        };
    }

    // ---------- Layar selesai / hasil ----------
    function renderDone(s) {
        running = false;
        stopTimers();
        $('bar-title').textContent = 'Ujian selesai';
        $('timer').style.display = 'none';

        const hasResult = Array.isArray(s.results) && s.results.length > 0;
        if (hasResult) {
            const score = (s.score ?? 0);
            const cc = Number(s.correct_count ?? 0), tq = Number(s.total_questions ?? 0);
            const per = s.results.map(r => {
                const q = questions.find(x => String(x.question_bank_id) === String(r.question_bank_id));
                const label = q ? (r.question_bank_id + '. ' + String(q.pertanyaan ?? '').slice(0, 46) + '…') : ('Soal ' + r.question_bank_id);
                return '<li>' + (r.is_benar ? '✅' : '❌') + ' ' + escapeHtml(label) + (r.is_benar ? '' : ' <span style="color:#9ca3af;">(0)</span>') + '</li>';
            }).join('');
            $('done-body').innerHTML =
                '<span class="badge">✅ Sudah dikoreksi guru</span>' +
                '<div class="score-big">' + escapeHtml(String(score)) + '</div>' +
                '<p class="sub" style="margin-bottom:6px;">Benar ' + cc + ' dari ' + tq + ' soal</p>' +
                (s.total_questions ? '<p class="sub">Nilai akhir tersimpan pada penilaian guru.</p>' : '') +
                '<ul class="perq" style="text-align:left;">' + per + '</ul>';
        } else {
            $('done-body').innerHTML =
                '<span class="badge">📤 Jawaban terkirim</span>' +
                '<h1 style="margin-top:10px;">Menunggu koreksi guru</h1>' +
                '<p class="sub">Jawaban Anda aman di server. Hasil akan muncul otomatis setelah guru melakukan koreksi (guru perlu online untuk menerima jawaban). Tekan "Periksa hasil" nanti untuk memuat hasil.</p>' +
                (s.waktu_submit ? '<p class="sub">Dikumpulkan: ' + escapeHtml(new Date(s.waktu_submit).toLocaleString('id-ID')) + '</p>' : '');
        }
        renderMath($('done-body'));   // V11a: formula pada rincian hasil
        show('screen-done');
    }

    function escapeHtml(v) {
        return String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    }

    // ---------- Ujian ----------
    function startTimers() {
        const total = Math.max(1, Number(exam.durasi_menit ?? 60)) * 60 * 1000;
        const startAt = new Date(sess.waktu_mulai).getTime();

        timerId = setInterval(function tick() {
            const remain = startAt + total - Date.now();
            const el = $('timer');
            if (remain <= 0) {
                el.textContent = '00:00';
                el.className = 'timer crit';
                finishExam(true); // auto-submit saat waktu habis
                return;
            }
            el.textContent = fmt(remain);
            el.className = 'timer' + (remain < 60000 ? ' crit' : remain < 300000 ? ' warn' : '');
        }, 500);

        autosaveId = setInterval(async function () {
            if (!running) { return; }
            sess.updated_at = new Date().toISOString();
            await CLOUD.dbPut('cbt_sessions', sess);   // draft lokal — TANPA menaikkan version
            $('save-note').textContent = 'Draft tersimpan ' + new Date().toLocaleTimeString('id-ID');
        }, 10000);
    }

    function stopTimers() {
        if (timerId) { clearInterval(timerId); timerId = null; }
        if (autosaveId) { clearInterval(autosaveId); autosaveId = null; }
    }

    function renderNav() {
        const grid = $('navgrid');
        grid.innerHTML = '';
        questions.forEach((q, i) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'navbtn' + (i === current ? ' active' : '') + (answered(q) ? ' filled' : '');
            b.textContent = String(i + 1);
            b.title = answered(q) ? 'Sudah dijawab' : 'Belum dijawab';
            b.addEventListener('click', function () { current = i; renderQuestion(); });
            grid.appendChild(b);
        });
    }

    function answered(q) {
        if (isMajemuk(q)) {
            return multipleAnswers(q).length > 0;
        }

        const a = sess.answers[String(q.question_bank_id)];
        return a !== null && a !== undefined && String(a).trim() !== '';
    }

    function renderQuestion() {
        const q = questions[current];
        $('qmeta').textContent = 'Soal ' + (current + 1) + ' dari ' + questions.length
            + ' · ' + String(q.jenis_soal ?? 'pg').toUpperCase()
            + ' · bobot ' + escapeHtml(String(q.bobot_nilai ?? 1));

        const jenisPetunjuk = String(q.jenis_soal ?? '').trim().toLowerCase();
        $('qtype-instruction').textContent = q.tka_category ? ''
            : ['majemuk', 'pilihan_majemuk', 'multiple_choice_complex'].includes(jenisPetunjuk)
                ? 'Untuk soal ini, Anda diperbolehkan memilih lebih dari satu jawaban.'
                : ['benar_salah', 'true_false'].includes(jenisPetunjuk)
                    ? 'Soal ini adalah pernyataan benar salah. Jawab A jika pernyataan benar, dan jawab B jika pernyataan salah.' : '';
        $('qtext').textContent = q.pertanyaan ?? '';

        const box = $('qoptions');
        box.innerHTML = '';
        const jenis = String(q.jenis_soal ?? '').toLowerCase();
        const isEssay = jenis.includes('esai')
            || jenis.includes('isian')
            || jenis.includes('singkat')
            || optionEntries(q).length === 0;

        if (isEssay) {
            const ta = document.createElement('textarea');
            ta.className = 'answer';
            ta.placeholder = 'Tulis jawaban Anda di sini…';
            ta.value = String(sess.answers[String(q.question_bank_id)] ?? '');
            ta.addEventListener('input', function () {
                sess.answers[String(q.question_bank_id)] = ta.value;
                refreshNav();
            });
            box.appendChild(ta);
        } else if (isMajemuk(q)) {
            const selected = multipleAnswers(q);

            optionEntries(q).forEach(opt => {
                const isSelected = selected.includes(opt.key);
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'opt' + (isSelected ? ' sel' : '');
                b.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                b.innerHTML = '<span class="key">' + (isSelected ? '☑ ' : '☐ ') + escapeHtml(opt.key) + '.</span><span>' + escapeHtml(opt.text) + '</span>';
                b.addEventListener('click', function () {
                    const next = multipleAnswers(q);
                    const pos = next.indexOf(opt.key);
                    if (pos >= 0) next.splice(pos, 1);
                    else next.push(opt.key);
                    sess.answers[String(q.question_bank_id)] = [...new Set(next)].sort();
                    renderQuestion();
                    refreshNav();
                });
                box.appendChild(b);
            });

        } else {
            optionEntries(q).forEach(opt => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'opt' + (String(sess.answers[String(q.question_bank_id)] ?? '').toUpperCase() === opt.key ? ' sel' : '');
                const tfKeys = jenis === 'benar_salah' && optionEntries(q).some(x => x.key === 'S');
                const displayKey = jenis === 'benar_salah' && tfKeys ? (opt.key === 'B' ? 'A' : opt.key === 'S' ? 'B' : opt.key) : opt.key;
                b.innerHTML = '<span class="key">' + escapeHtml(displayKey) + '.</span><span>' + escapeHtml(opt.text) + '</span>';
                b.addEventListener('click', function () {
                    sess.answers[String(q.question_bank_id)] = opt.key;
                    renderQuestion(); refreshNav();
                });
                box.appendChild(b);
            });
        }

        // V11a: formula pada teks soal dan opsi jawaban.
        // (Kolom jawaban esai dikecualikan via ignoredClasses 'answer'.)
        renderMath($('qtext'));
        renderMath(box);

        $('btn-prev').disabled = current === 0;
        $('btn-next').disabled = current >= questions.length - 1;
        renderNav();
    }

    function refreshNav() {
        document.querySelectorAll('#navgrid .navbtn').forEach((b, i) => {
            b.classList.toggle('filled', answered(questions[i]));
        });
    }

    async function finishExam(auto) {
        if (!running) { return; }
        const unanswered = questions.filter(q => !answered(q)).length;

        if (!auto) {
            const msg = unanswered > 0
                ? 'Masih ada ' + unanswered + ' soal belum dijawab.\n\nTetap kumpulkan sekarang?'
                : 'Kumpulkan jawaban sekarang?\n\nSetelah dikumpulkan, ujian tidak dapat dibuka lagi.';
            if (!window.confirm(msg)) { return; }
        } else {
            window.alert('Waktu habis. Jawaban dikumpulkan otomatis.');
        }

        running = false;
        stopTimers();

        const now = new Date().toISOString();
        sess.status = 'selesai';
        sess.waktu_submit = now;
        sess.sync_version = Math.max(1, Number(sess.sync_version ?? 0)) + 1; // attempt tunggal: version submit
        sess.sync_status = 'pending';
        sess.updated_at = now;

        await CLOUD.saveCbtSession(sess); // simpan + picu push ke Cloud

        renderDone(sess);
    }

    // ---------- Init ----------
    const s = await CLOUD.session().catch(() => null);
    if (!s) { window.location.href = '{{ route('pwa.cloud.login') }}'; return; }
    if (SERVER_LOGIN_ID === '' || String(s.login_id ?? '').trim() !== SERVER_LOGIN_ID) {
        window.location.href = '{{ route('pwa.cloud.login') }}'; return; // guard identitas (pola V8)
    }

    exam = await CLOUD.dbGet('cbt_exams', EXAM_SYNC_ID);

    if (!exam) {
        $('screen-loading').classList.remove('on');
        show('screen-missing');
        $('btn-sync-missing').addEventListener('click', async function () {
            this.disabled = true; this.textContent = '⏳ Menyinkronkan...';
            await CLOUD.sync().catch(() => {});
            window.location.reload();
        });
        return;
    }

    $('bar-title').textContent = exam.judul_ujian ?? 'Ujian CBT';

    if (exam.status_aktif === false) {
        $('screen-loading').classList.remove('on');
        show('screen-inactive');
        return;
    }

    questions = (Array.isArray(exam.questions) ? exam.questions.slice() : [])
        .sort((a, b) => Number(a.urutan ?? 0) - Number(b.urutan ?? 0));

    const found = await findSession(s.login_id);

    if (found.done) { renderDone(found.done); return; }

    // Resume ATAU mulai baru
    sess = found.draft || newSession(s.login_id);
    if (!sess.answers || typeof sess.answers !== 'object') { sess.answers = {}; }

    // Periksa sisa waktu — jika sudah lewat dari sesi lama, kumpulkan otomatis
    const total = Math.max(1, Number(exam.durasi_menit ?? 60)) * 60 * 1000;
    const elapsed = Date.now() - new Date(sess.waktu_mulai).getTime();
    if (elapsed >= total) {
        sess.status = 'selesai';
        sess.waktu_submit = new Date().toISOString();
        sess.sync_version = Math.max(1, Number(sess.sync_version ?? 0)) + 1;
        sess.sync_status = 'pending';
        await CLOUD.saveCbtSession(sess);
        renderDone(sess);
        return;
    }

    // Ambil hasil yang mungkin sudah dikoreksi (dipanggil dari layar "menunggu")
    $('btn-refresh-result').addEventListener('click', async function () {
        this.disabled = true; this.textContent = '⏳ Memeriksa...';
        await CLOUD.sync().catch(() => {});
        const fresh = await CLOUD.dbGet('cbt_sessions', sess.sync_id);
        if (fresh) { renderDone(fresh); }
        else { this.disabled = false; this.textContent = '🔄 Periksa hasil'; }
    });

    // Jalankan ujian
    $('screen-loading').classList.remove('on');
    show('screen-exam');
    startTimers();
    renderQuestion();
    running = true;

    // Telemetri keluar aplikasi (fokus hilang) — sinyal, bukan kontrol
    document.addEventListener('visibilitychange', function () {
        if (!running) { return; }
        if (document.visibilityState === 'hidden') {
            sess.focus_lost_count = Number(sess.focus_lost_count ?? 0) + 1;
            const fb = $('focus-badge');
            fb.style.display = 'inline-flex';
            fb.textContent = '👀 ' + sess.focus_lost_count;
        }
    });

    // Navigasi
    $('btn-prev').addEventListener('click', function () { if (current > 0) { current--; renderQuestion(); } });
    $('btn-next').addEventListener('click', function () { if (current < questions.length - 1) { current++; renderQuestion(); } });
    $('btn-submit').addEventListener('click', function () { finishExam(false); });

    // Simpan draft saat tab ditutup/diminimalkan
    window.addEventListener('pagehide', function () {
        if (running) { CLOUD.dbPut('cbt_sessions', sess).catch(() => {}); }
    });

});
</script>
</body>
</html>