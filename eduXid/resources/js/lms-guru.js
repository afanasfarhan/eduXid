(function () {

    'use strict';

    const SYNC_INTERVAL = 5000;

    let syncRunning = false;
    let syncTimer = null;
    let formDirty = false;

    function isLmsGuruPage() {
        return window.location.pathname.startsWith('/lms/');
    }

    function isFormPage() {
        const path = window.location.pathname;
        return path.includes('/create') || path.includes('/edit');
    }

    function hasDirtyForm() {
        if (formDirty) { return true; }
        const active = document.activeElement;
        if (active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) { return true; }
        return false;
    }

    document.addEventListener('input', function (event) {
        const tag = event.target && event.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
            if (event.target.type !== 'hidden') { formDirty = true; }
        }
    }, true);

    document.addEventListener('submit', function () {
        formDirty = false;
    }, true);

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    async function syncGuru() {
        if (!navigator.onLine) { return; }
        if (syncRunning) { return; }

        syncRunning = true;

        try {
            const response = await fetch('/lms/sync/auto', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const result = await response.json();

            if (!result.success) {
                return;
            }

            const received = Number(result.received || 0);
            const applied = Number(result.applied || 0);
            const failed = Number(result.failed || 0);

            if (
                received > 0 &&
                applied > 0 &&
                failed === 0 &&
                isLmsGuruPage() &&
                !isFormPage() &&
                !hasDirtyForm()
            ) {
                window.location.reload();
                return;
            }

            window.dispatchEvent(new CustomEvent('eduxid:lms-guru-sync', {
                detail: result
            }));
        } catch (error) {
            /* silent — retry otomatis pada interval berikutnya */
        } finally {
            syncRunning = false;
        }
    }

    function startAutoSync() {
        if (syncTimer !== null) { return; }
        syncGuru();
        syncTimer = window.setInterval(syncGuru, SYNC_INTERVAL);
    }

    function stopAutoSync() {
        if (syncTimer === null) { return; }
        window.clearInterval(syncTimer);
        syncTimer = null;
    }

    window.addEventListener('online', function () {
        startAutoSync();
    });

    window.addEventListener('offline', function () {
        stopAutoSync();
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            startAutoSync();
        } else {
            stopAutoSync();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        if (navigator.onLine) {
            startAutoSync();
        }
    });

})();