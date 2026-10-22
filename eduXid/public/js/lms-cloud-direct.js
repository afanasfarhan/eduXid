/*
 * eduXid PWA v2 — CLOUD DIRECT
 * HP siswa <-> EDUXID Cloud (Worker + D1), tanpa bergantung laptop guru.
 * Offline-first: semua baca/tulis operasional via IndexedDB.
 */
(function () {
    'use strict';

    const CLOUD_URL = (window.EDUXID_CLOUD_URL || '').replace(/\/+$/, '');
    if (!CLOUD_URL) { console.error('eduXid v2: EDUXID_CLOUD_URL belum diset.'); }

    const DB_NAME = 'eduxid_lms_v2';
    const DB_VERSION = 3;
    const STORES = [
        'courses', 'topics', 'materials', 'assignments',
        'submissions', 'cbt_exams', 'cbt_sessions', 'material_files',
        'applied_events', 'pending_acks', 'auth', 'meta',
    ];

    const SYNC_INTERVAL_MS = 30000;
    const MAX_PULL_LOOPS = 20;
    const ACK_CHUNK = 100;
    const NEED_LOGIN = 'NEED_LOGIN';

    function randomId() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') { return crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    let db = null;
    let syncing = false;
    let timer = null;

    // ---------- IndexedDB dasar (self-open: aman dipanggil kapan pun) ----------
    function open() {
        if (db) { return Promise.resolve(db); }
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (e) => {
                const d = e.target.result;
                for (const s of STORES) {
                    const keyPath = (s === 'meta' || s === 'auth') ? 'key'
                        : (s === 'applied_events' || s === 'pending_acks') ? 'event_id'
                        : 'sync_id';
                    if (!d.objectStoreNames.contains(s)) {
                        d.createObjectStore(s, { keyPath });
                    } else if (e.oldVersion < 2 && s === 'auth') {
                        // v1 membuat store 'auth' dengan keyPath 'sync_id' (salah).
                        // Buat ulang dengan keyPath 'key'. Isi = sesi login saja, aman di-reset.
                        d.deleteObjectStore(s);
                        d.createObjectStore(s, { keyPath: 'key' });
                    }
                }
            };
            req.onsuccess = (e) => { db = e.target.result; db.onversionchange = () => { db.close(); db = null; }; resolve(db); };
            req.onerror = () => reject(req.error);
        });
    }
    function tx(store, mode) { return db.transaction(store, mode); }
    async function get(store, id) { await open(); return new Promise((res, rej) => { const r = tx(store, 'readonly').objectStore(store).get(id); r.onsuccess = () => res(r.result || null); r.onerror = () => rej(r.error); }); }
    async function getAll(store) { await open(); return new Promise((res, rej) => { const r = tx(store, 'readonly').objectStore(store).getAll(); r.onsuccess = () => res(r.result || []); r.onerror = () => rej(r.error); }); }
    async function put(store, data) { await open(); return new Promise((res, rej) => { const r = tx(store, 'readwrite').objectStore(store).put(data); r.onsuccess = () => res(data); r.onerror = () => rej(r.error); }); }
    async function del(store, id) { await open(); return new Promise((res, rej) => { const r = tx(store, 'readwrite').objectStore(store).delete(id); r.onsuccess = () => res(true); r.onerror = () => rej(r.error); }); }

    async function getMeta(k, d = null) { const r = await get('meta', k); return r ? r.value : d; }
    async function setMeta(k, v) { await put('meta', { key: k, value: v, updated_at: new Date().toISOString() }); return v; }

    // ---------- Auth ----------
    async function login(schoolId, loginId, password) {
        const res = await fetch(CLOUD_URL + '/api/pwa/auth/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ school_id: String(schoolId).trim(), login_id: String(loginId).trim(), password: String(password) }),
        });
        const body = await res.json().catch(() => null);
        if (!res.ok || !body || body.success !== true) {
            throw new Error((body && body.error && body.error.message) || 'Login gagal (HTTP ' + res.status + ').');
        }
        const d = body.data;
        await put('auth', {
            key: 'session',
            device_token: d.device_token,
            device_id: d.device_id,
            school_id: d.school_id,
            login_id: d.login_id,
            name: d.name,
            homeroom_key: d.homeroom_key,
            logged_in_at: new Date().toISOString(),
        });
        await setMeta('student_login_id', d.login_id);
        window.dispatchEvent(new CustomEvent('eduxid:login', { detail: { name: d.name, login_id: d.login_id } }));
        return d;
    }

    async function session() {
        const s = await get('auth', 'session');
        if (!s || !s.device_token) { throw new Error(NEED_LOGIN); }
        return s;
    }

    async function logout() { try { await del('auth', 'session'); } catch (e) { /* ignore */ } }

    // ---------- HTTP ----------
    async function api(method, path, body) {
        const s = await session();
        const res = await fetch(CLOUD_URL + path, {
            method,
            headers: {
                'Authorization': 'Bearer ' + s.device_token,
                'Accept': 'application/json',
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (res.status === 401) {
            await logout();
            throw new Error(NEED_LOGIN);
        }
        const data = await res.json().catch(() => null);
        if (!res.ok || !data || data.success !== true) {
            throw new Error('Cloud ' + method + ' ' + path + ' gagal (HTTP ' + res.status + ').');
        }
        return data;
    }

    // ---------- PULL / APPLY / ACK ----------
    function vOf(change) { return Number((change.payload && change.payload.sync_version) ?? change.version ?? 0); }

    async function markApplied(eventId) {
        await put('applied_events', { event_id: eventId, applied_at: new Date().toISOString() });
        await put('pending_acks', { event_id: eventId });
    }

    function ownerFieldsFromTeacher(payload) {
        const hasScore = payload.score !== null && payload.score !== undefined && payload.score !== '';
        const hasFeedback = payload.teacher_feedback !== null && payload.teacher_feedback !== undefined && payload.teacher_feedback !== '';
        return hasScore || hasFeedback || payload.status === 'graded';
    }

    async function applySubmission(change) {
        const p = change.payload || {};
        const s = await session();
        const me = String(p.student_id ?? p.student_nisn ?? '').trim();
        // Fail-closed: submission siswa lain tidak pernah disentuh (§25).
        if (me !== '' && me !== s.login_id) { return 'skipped'; }

        const id = String(p.sync_id ?? change.entity_id);

        // Delete dicek SEBELUM LWW guard agar tidak pernah terskip.
        if (change.action === 'delete') {
            const ex = await get('submissions', id);
            if (ex) { await del('submissions', id); }
            return 'applied';
        }

        let row = await get('submissions', id);
        if (row && Number(row.sync_version ?? 0) > vOf(change)) { return 'skipped'; }
        row = row || { sync_id: id, id: p.id ?? null, created_at: p.created_at ?? new Date().toISOString() };

        if (ownerFieldsFromTeacher(p)) {
            // Event dari guru (§19/§21): hanya field milik guru yang ditimpa.
            row.score = p.score ?? row.score ?? null;
            row.teacher_feedback = p.teacher_feedback ?? row.teacher_feedback ?? null;
            if (p.status === 'graded') { row.status = 'graded'; }
        } else {
            // Event jawaban (dari siswa ini): jawaban diperbarui, nilai guru dipertahankan.
            row.assignment_id = p.assignment_id ?? row.assignment_id ?? null;
            row.assignment_sync_id = p.assignment_sync_id ?? row.assignment_sync_id ?? null;
            row.answer_text = p.answer_text ?? row.answer_text ?? null;
            row.file_path = p.file_path ?? row.file_path ?? null;
            row.file_name = p.file_name ?? row.file_name ?? null;
            row.submitted_at = p.submitted_at ?? row.submitted_at ?? null;
            row.status = p.status ?? row.status ?? 'submitted';
        }
        row.sync_version = vOf(change);
        row.sync_status = 'synced';
        row.last_synced_at = new Date().toISOString();
        await put('submissions', row);
        return 'applied';
    }

    const GENERIC_STORE = { lms_course: 'courses', lms_topic: 'topics', lms_material: 'materials', lms_assignment: 'assignments' };

    async function applyGeneric(change) {
        const type = change.entity_type;
        const store = GENERIC_STORE[type];
        if (!store) { return 'unknown'; }
        const p = change.payload || {};
        const id = String(p.sync_id ?? change.entity_id);

        if (change.action === 'delete') {
            const ex = await get(store, id);
            if (ex) { await del(store, ex.sync_id); }
            return 'applied';
        }
        let ex = await get(store, id);
        if (ex && Number(ex.sync_version ?? 0) > vOf(change)) { return 'skipped'; }
        ex = Object.assign({}, ex || { sync_id: id }, p, { sync_version: vOf(change) });
        await put(store, ex);
        return 'applied';
    }

    async function applyCbtExam(change) {
        const p = change.payload || {};
        const id = String(p.sync_id ?? change.entity_id);
        if (change.action === 'delete') { const ex = await get('cbt_exams', id); if (ex) { await del('cbt_exams', ex.sync_id); } return 'applied'; }
        let ex = await get('cbt_exams', id);
        if (ex && Number(ex.sync_version ?? 0) > vOf(change)) { return 'skipped'; }
        ex = Object.assign({}, ex || { sync_id: id }, p, { sync_version: vOf(change) });
        await put('cbt_exams', ex);
        return 'applied';
    }

    async function applyCbtResult(change) {
        const p = change.payload || {};
        const sid = p.session_sync_id;
        if (!sid) { return 'applied'; } // tidak bisa diproses — jangan biarkan menggantung (anti-freeze)
        const sess = await get('cbt_sessions', String(sid));
        if (!sess) { return 'applied'; }
        sess.score = p.score ?? sess.score ?? null;
        sess.correct_count = p.correct_count ?? null;
        sess.total_questions = p.total_questions ?? null;
        sess.results = Array.isArray(p.results) ? p.results : sess.results;
        sess.sync_status = 'synced';
        sess.last_synced_at = new Date().toISOString();
        await put('cbt_sessions', sess);
        return 'applied';
    }

    async function applyEvent(change) {
        const t = change.entity_type;
        if (t === 'lms_assignment_submission') { return applySubmission(change); }
        if (t === 'cbt_exam') { return applyCbtExam(change); }
        if (t === 'cbt_result') { return applyCbtResult(change); }
        if (GENERIC_STORE[t]) { return applyGeneric(change); }
        console.warn('eduXid v2: entity tidak dikenal, di-ACK tanpa apply:', t);
        return 'applied'; // anti-freeze: entity tak dikenal tidak boleh memblokir
    }

    async function ackChunked() {
        const ids = (await getAll('pending_acks')).map((r) => r.event_id);
        let acked = 0;
        for (let i = 0; i < ids.length; i += ACK_CHUNK) {
            const chunk = ids.slice(i, i + ACK_CHUNK);
            try {
                const res = await api('POST', '/api/sync/ack', { event_ids: chunk });
                if (res && res.success === true) {
                    for (const id of chunk) { await del('pending_acks', id); }
                    acked += chunk.length;
                }
            } catch (e) {
                // ACK gagal (jaringan) — data sudah aman di lokal; sisanya menunggu siklus berikutnya.
                console.warn('eduXid v2: ACK tertunda, dicoba ulang pada sync berikutnya.', e.message);
                break;
            }
        }
        return acked;
    }

    async function pullOnce() {
        const res = await api('GET', '/api/sync/pull?limit=100');
        const events = (res.data && res.data.events) || [];
        let applied = 0, skipped = 0, failed = 0;
        for (const ev of events) {
            try {
                if (await get('applied_events', ev.event_id)) { skipped++; await put('pending_acks', { event_id: ev.event_id }); continue; }
                const r = await applyEvent(ev);
                if (r === 'failed') { failed++; continue; }
                await markApplied(ev.event_id);
                if (r === 'skipped') { skipped++; } else { applied++; }
            } catch (e) {
                failed++;
                console.error('eduXid v2: apply gagal', ev.event_id, e);
            }
        }
        await ackChunked();
        return { received: events.length, applied, skipped, failed, has_more: !!(res.data && res.data.has_more) };
    }

    // ---------- PUSH (jawaban & CBT) ----------
    function stableEventId(syncId, version) { return syncId + '::v' + Math.max(1, Number(version || 1)); }

    async function pushOne(change, markSynced) {
        const s = await session();
        const body = {
            events: [{
                event_id: change.event_id,
                school_id: s.school_id,
                source_device_id: s.device_id,
                action: change.action,
                entity_type: change.entity_type,
                entity_id: change.entity_id,
                version: change.version,
                created_at: new Date().toISOString(),
                payload: change.payload,
            }],
        };
        const res = await api('POST', '/api/sync/push', body);
        const results = (res.data && res.data.results) || [];
        const mine = results.find((r) => r.event_id === change.event_id);
        const st = mine && String(mine.status).toLowerCase();
        if (st === 'accepted' || st === 'duplicate') { await markSynced(false); return true; }
        if (st === 'rejected') { await markSynced(true); return false; }
        throw new Error('Push tidak terkonfirmasi.');
    }

    async function pushPending() {
        let pushed = 0, failed = 0;
        const s = await session();

        for (const sub of await getAll('submissions')) {
            if (sub.sync_status !== 'pending' || sub.deleted) { continue; }
            const version = Math.max(1, Number(sub.sync_version || 1));
            try {
                await pushOne({
                    event_id: stableEventId(sub.sync_id, version),
                    action: 'upsert',
                    entity_type: 'lms_assignment_submission',
                    entity_id: sub.sync_id,
                    version,
                    // KONTRAK: siswa TIDAK mengirim score/teacher_feedback (milik guru, §19/§21).
                    payload: {
                        sync_id: sub.sync_id,
                        id: sub.id ?? null,
                        entity: 'lms_assignment_submission',
                        assignment_id: sub.assignment_id ?? null,
                        assignment_sync_id: sub.assignment_sync_id ?? null,
                        student_id: s.login_id,
                        student_nisn: s.login_id,
                        answer_text: sub.answer_text ?? null,
                        file_path: sub.file_path ?? null,
                        file_name: sub.file_name ?? null,
                        submitted_at: sub.submitted_at ?? new Date().toISOString(),
                        status: sub.status ?? 'submitted',
                        sync_version: version,
                        created_at: sub.created_at ?? new Date().toISOString(),
                        updated_at: new Date().toISOString(),
                    },
                }, async (rejected) => {
                    sub.sync_status = rejected ? 'rejected' : 'synced';
                    sub.last_synced_at = new Date().toISOString();
                    await put('submissions', sub);
                });
                pushed++;
            } catch (e) {
                failed++;
                console.warn('eduXid v2: push submission tertunda (tetap aman lokal).', sub.sync_id, e.message);
            }
        }

        for (const sess of await getAll('cbt_sessions')) {
            if (sess.sync_status === 'synced' || sess.status !== 'selesai') { continue; }
            const version = Math.max(1, Number(sess.sync_version || 1));
            const answers = Object.entries(sess.answers || {}).map(([k, v]) => ({ question_bank_id: Number(k), jawaban_siswa: v }));
            try {
                await pushOne({
                    event_id: stableEventId(sess.sync_id, version),
                    action: 'upsert',
                    entity_type: 'cbt_answer',
                    entity_id: sess.sync_id,
                    version,
                    payload: {
                        sync_id: sess.sync_id,
                        entity: 'cbt_answer',
                        exam_sync_id: sess.exam_sync_id,
                        student_id: s.login_id,
                        answers,
                        waktu_mulai: sess.waktu_mulai ?? null,
                        waktu_submit: sess.waktu_submit ?? null,
                        status: 'selesai',
                        focus_lost_count: sess.focus_lost_count ?? 0,
                        sync_version: version,
                    },
                }, async () => {
                    sess.sync_status = 'synced';
                    sess.last_synced_at = new Date().toISOString();
                    await put('cbt_sessions', sess);
                });
                pushed++;
            } catch (e) {
                failed++;
                console.warn('eduXid v2: push CBT tertunda.', sess.sync_id, e.message);
            }
        }
        return { pushed, failed };
    }

    // ---------- PENULISAN LOKAL (dipakai UI) ----------
    async function saveSubmission(sub) {
        if (!sub) { throw new Error('Data submission kosong.'); }
        if (!sub.sync_id) { sub.sync_id = randomId(); }
        sub.sync_status = 'pending';
        sub.sync_version = Math.max(1, Number(sub.sync_version ?? 0)) + 1;
        sub.updated_at = new Date().toISOString();
        if (!sub.created_at) { sub.created_at = sub.updated_at; }
        await put('submissions', sub);
        sync().catch(() => {});
        return sub;
    }

    async function saveCbtSession(sess) {
        if (!sess) { throw new Error('Data sesi CBT kosong.'); }
        if (!sess.sync_id) { sess.sync_id = randomId(); }
        const finished = sess.status === 'selesai';
        if (finished) { sess.sync_version = Math.max(1, Number(sess.sync_version ?? 0)) + 1; }
        if (!sess.updated_at) { sess.updated_at = new Date().toISOString(); }
        await put('cbt_sessions', sess);
        if (finished) { sync().catch(() => {}); }
        return sess;
    }

    // ---------- SYNC UTAMA ----------
    async function sync() {
        if (syncing) { return { ok: false, busy: true }; }
        syncing = true;
        try {
            if (!navigator.onLine) { return { ok: true, offline: true }; }
            const pull = { received: 0, applied: 0, skipped: 0, failed: 0 };
            let loops = 0, more = true;
            while (more && loops < MAX_PULL_LOOPS) {
                const r = await pullOnce();
                pull.received += r.received; pull.applied += r.applied;
                pull.skipped += r.skipped; pull.failed += r.failed;
                more = r.has_more; loops++;
            }
            const push = await pushPending();
            await setMeta('last_synced_at', new Date().toISOString());
            window.dispatchEvent(new CustomEvent('eduxid:sync-status', { detail: { status: 'synced', pull, push } }));
            return { ok: true, pull, push };
        } catch (e) {
            if (e.message === NEED_LOGIN) {
                window.dispatchEvent(new CustomEvent('eduxid:need-login'));
                return { ok: false, need_login: true };
            }
            console.warn('eduXid v2: sync gagal, data lokal tetap aman.', e.message);
            return { ok: false, error: e.message };
        } finally {
            syncing = false;
        }
    }

    function startAutoSync() {
        if (timer) { clearInterval(timer); }
        timer = setInterval(() => { if (navigator.onLine && !syncing) { sync(); } }, SYNC_INTERVAL_MS);
    }

    window.addEventListener('online', () => sync().catch(() => {}));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && navigator.onLine) { startAutoSync(); sync().catch(() => {}); }
    });

    window.eduXidCloud = {
        login: login,
        logout: logout,
        sync: sync,
        session: () => session().catch(() => null),
        ready: open,
        dbAll: (store) => getAll(store),
        dbGet: (store, id) => get(store, id),
        dbPut: (store, data) => put(store, data),
        dbDelete: (store, id) => del(store, id),
        getLastSync: () => getMeta('last_synced_at'),
        saveSubmission: saveSubmission,
        saveCbtSession: saveCbtSession,
        getAssignments: () => getAll('assignments'),
        getMaterials: () => getAll('materials'),
        getCourses: () => getAll('courses'),
        getSubmission: (syncId) => get('submissions', syncId),
        getCbtExam: (syncId) => get('cbt_exams', syncId),
    };

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', startAutoSync); } else { startAutoSync(); }
})();