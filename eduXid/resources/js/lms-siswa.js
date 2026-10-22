/*
 * eduXid LMS Siswa — FASADE KOMPATIBILITAS (PERMANEN)
 *
 * Seluruh API lama window.eduXidLmsDb kini berjalan DI ATAS client v2
 * (lms-cloud-direct.js → DB eduxid_lms_v2 → EDUXID Cloud langsung).
 *
 * - Satu database, satu mesin sync, satu device identity. Tidak ada
 *   lagi dua client berebut antrean event (root cause event hilang).
 * - Halaman manapun yang memuat file ini otomatis memakai v2.
 * - Identitas diambil dari sesi login Cloud (bukan dari global blade),
 *   sehingga halaman CBT lama tetap bekerja tanpa perubahan apa pun.
 */
(function () {
    'use strict';

    let cloudPromise = null;

    function ensureCloud() {
        if (window.eduXidCloud) { return Promise.resolve(window.eduXidCloud); }
        if (!cloudPromise) {
            cloudPromise = new Promise(function (resolve, reject) {
                const s = document.createElement('script');
                s.src = '/js/lms-cloud-direct.js';
                s.onload = function () { window.eduXidCloud ? resolve(window.eduXidCloud) : reject(new Error('eduXidCloud tidak tersedia.')); };
                s.onerror = function () { reject(new Error('lms-cloud-direct.js gagal dimuat.')); };
                document.head.appendChild(s);
            });
        }
        return cloudPromise;
    }

    const C = ensureCloud;

    async function sess() {
        const c = await C();
        const s = await c.session();
        if (!s) { throw new Error('NEED_LOGIN'); }
        return s;
    }

    function homeroomIdsFromKey(key) {
        return String(key || '')
            .split('|')
            .map(function (s) { return s.trim(); })
            .filter(function (s) { return s !== ''; })
            .map(Number)
            .filter(function (n) { return Number.isFinite(n) && n > 0; });
    }

    let shimSyncing = false;
    let shimTimer = null;

    window.eduXidLmsDb = {
        // ----- IndexedDB (diteruskan ke v2) -----
        open: function () { return C().then(function (c) { return c.ready(); }); },
        get: function (store, id) { return C().then(function (c) { return c.dbGet(store, id); }); },
        getAll: function (store) { return C().then(function (c) { return c.dbAll(store); }); },
        put: function (store, data) { return C().then(function (c) { return c.dbPut(store, data); }); },
        delete: function (store, id) { return C().then(function (c) { return c.dbDelete(store, id); }); },

        // ----- Identitas (dari sesi Cloud — server-side truth) -----
        getStudentLoginId: async function () { try { const s = await sess(); return String(s.login_id ?? ''); } catch (e) { return ''; } },
        saveStudentLoginId: async function (v) { return v; },
        getStudentNisn: async function () { try { const s = await sess(); return String(s.login_id ?? ''); } catch (e) { return ''; } },
        saveStudentNisn: async function (v) { return v; },
        getDeviceId: async function () { try { const s = await sess(); return String(s.device_id ?? ''); } catch (e) { return ''; } },
        getSchoolId: async function () { try { const s = await sess(); return String(s.school_id ?? ''); } catch (e) { return ''; } },
        getStudentHomeroomIds: async function () { try { const s = await sess(); return homeroomIdsFromKey(s.homeroom_key); } catch (e) { return []; } },
        getStudentHomeroomKey: async function () { try { const s = await sess(); return s.homeroom_key || null; } catch (e) { return null; } },
        getStudentHomeroomId: async function () {
            const ids = await window.eduXidLmsDb.getStudentHomeroomIds();
            return ids.length > 0 ? ids[0] : null;
        },

        // Registrasi: tidak diperlukan lagi — login Cloud yang mendaftarkan device.
        registerStudentDevice: async function () {
            const s = await sess();
            return { success: true, already_registered: true, device: { device_id: s.device_id } };
        },

        // ----- Cache PDF (store material_files di DB v2) -----
        getMaterialFile: function (syncId) {
            const id = String(syncId ?? '').trim();
            if (!id) { return Promise.resolve(null); }
            return C().then(function (c) { return c.dbGet('material_files', id); });
        },
        cacheMaterialFile: async function (material) {
            const c = await C();
            if (!material) { return { cached: false, skipped: true }; }
            const type = String(material.type ?? '').trim().toLowerCase();
            const mime = String(material.mime_type ?? '').trim().toLowerCase();
            const filePath = String(material.file_path ?? '').trim();
            const isPdf = type === 'pdf' || mime === 'application/pdf';
            if (!isPdf || !filePath) { return { cached: false, skipped: true }; }
            const syncId = String(material.sync_id ?? '').trim();
            if (!syncId) { return { cached: false, skipped: true }; }
            const existing = await c.dbGet('material_files', syncId);
            const ver = Number(material.sync_version ?? 0);
            if (existing && existing.blob instanceof Blob && existing.blob.size > 0
                && existing.file_path === filePath && Number(existing.sync_version ?? 0) === ver) {
                return { cached: true, skipped: true, sync_id: syncId };
            }
            const url = '/storage/' + filePath.replace(/^\/+/, '');
            try {
                const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                const blob = await res.blob();
                if (!blob || blob.size <= 0) { throw new Error('File PDF kosong.'); }
                await c.dbPut('material_files', {
                    sync_id: syncId,
                    material_id: material.id ?? null,
                    file_path: filePath,
                    file_name: material.file_name ?? 'Dokumen PDF',
                    mime_type: material.mime_type ?? 'application/pdf',
                    file_size: blob.size,
                    sync_version: ver,
                    blob: blob,
                    cached_at: new Date().toISOString(),
                });
                return { cached: true, skipped: false, sync_id: syncId, size: blob.size };
            } catch (error) {
                return { cached: false, skipped: false, error: error.message };
            }
        },
        deleteMaterialFile: async function (syncId) {
            const id = String(syncId ?? '').trim();
            if (!id) { return false; }
            const c = await C();
            try { await c.dbDelete('material_files', id); return true; } catch (e) { return false; }
        },

        // ----- Sync -----
        sync: function () { return C().then(function (c) { return c.sync(); }); },
        syncLmsSiswa: function () { return C().then(function (c) { return c.sync(); }); },

        pushPendingSubmissions: async function () {
            const c = await C();
            const subs = await c.dbAll('submissions');
            const before = subs.filter(function (s) { return s.sync_status === 'pending'; }).length;
            const r = await c.sync();
            const after = (await c.dbAll('submissions')).filter(function (s) { return s.sync_status === 'pending'; }).length;
            return { success: true, processed: before, synced: Math.max(0, before - after), failed: 0, failures: [], detail: r };
        },
        pushPendingCbtAnswers: async function () {
            const c = await C();
            const all = await c.dbAll('cbt_sessions');
            const before = all.filter(function (s) { return s.sync_status !== 'synced' && s.status === 'selesai'; }).length;
            const r = await c.sync();
            const after = (await c.dbAll('cbt_sessions')).filter(function (s) { return s.sync_status !== 'synced' && s.status === 'selesai'; }).length;
            return { success: true, processed: before, synced: Math.max(0, before - after), failed: 0, failures: [], detail: r };
        },

        repairSubmissionStudentIdentity: async function () {
            const c = await C();
            const s = await c.session().catch(function () { return null; });
            if (!s || !s.login_id) { return { repaired: 0, login_id: null }; }
            const subs = await c.dbAll('submissions');
            let repaired = 0;
            for (const sub of subs) {
                if (String(sub.student_id ?? '').trim() !== '') { continue; }
                sub.student_id = s.login_id;
                sub.sync_status = 'pending';
                sub.sync_version = Math.max(1, Number(sub.sync_version ?? 0)) + 1;
                sub.updated_at = new Date().toISOString();
                await c.dbPut('submissions', sub);
                repaired++;
            }
            return { repaired: repaired, login_id: s.login_id };
        },

        pullChanges: async function (cursor) {
            const c = await C();
            const r = await c.sync();
            const pull = (r && r.pull) || {};
            return {
                success: true,
                received: pull.received ?? 0,
                applied: pull.applied ?? 0,
                skipped: pull.skipped ?? 0,
                failed: pull.failed ?? 0,
                resolved_ids: [],
                cursor_before: Number(cursor ?? 0),
                cursor_after: Number(cursor ?? 0),
            };
        },

        getSyncStatus: async function () {
            const c = await C();
            const s = await c.session().catch(function () { return null; });
            const lastSyncedAt = await c.getLastSync().catch(function () { return null; });
            const pending = await c.dbAll('pending_acks').catch(function () { return []; });
            const ids = s ? homeroomIdsFromKey(s.homeroom_key) : [];
            return {
                status: navigator.onLine ? 'online' : 'offline',
                online: navigator.onLine,
                syncing: shimSyncing,
                device_id: s ? s.device_id : '',
                school_id: s ? s.school_id : '',
                student_login_id: s ? s.login_id : null,
                student_nisn: s ? s.login_id : null,
                student_homeroom_ids: ids,
                student_homeroom_key: s ? (s.homeroom_key || null) : null,
                device_registered: !!s,
                cursor: 0,
                pending_acks: Array.isArray(pending) ? pending.length : 0,
                last_synced_at: lastSyncedAt,
                last_result: null,
            };
        },
        getSyncCursor: async function () {
            const c = await C();
            return c.dbGet('meta', 'sync_cursor').then(function (r) { return r ? Number(r.value ?? 0) : 0; }).catch(function () { return 0; });
        },
        saveSyncCursor: async function (v) {
            const c = await C();
            await c.dbPut('meta', { key: 'sync_cursor', value: Math.max(0, Number(v ?? 0)), updated_at: new Date().toISOString() });
            return v;
        },

        getLocalData: async function () {
            const c = await C();
            const names = ['courses', 'topics', 'materials', 'material_files', 'assignments', 'submissions', 'cbt_exams', 'cbt_sessions', 'meta'];
            const out = {};
            for (const n of names) { out[n] = await c.dbAll(n); }
            return out;
        },

        startAutoSync: function () {
            if (shimTimer) { clearInterval(shimTimer); }
            shimTimer = setInterval(function () {
                if (navigator.onLine && !shimSyncing) { C().then(function (c) { return c.sync(); }).catch(function () {}); }
            }, 30000);
        },
        stopAutoSync: function () { if (shimTimer) { clearInterval(shimTimer); shimTimer = null; } },
    };

    // Inisialisasi ringan: sync awal bila sesi ada & online (v2 juga punya auto-sync sendiri).
    document.addEventListener('DOMContentLoaded', function () {
        window.eduXidLmsDb.repairSubmissionStudentIdentity().catch(function () {});
        if (navigator.onLine) {
            C().then(function (c) { return c.session(); }).then(function (s) {
                if (s) { return C().then(function (c) { return c.sync(); }); }
            }).catch(function () {});
        }
    });
})();