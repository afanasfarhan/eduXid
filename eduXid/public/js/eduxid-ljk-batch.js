(function (global) {
    'use strict';

    function init(config) {
        const $ = id => document.getElementById(id);
        const root = $('ljk-batch-mode');
        if (!root || !global.EduxidLjkOmr) return;

        const examEl = $('ljk-batch-exam');
        const dateEl = $('ljk-batch-date');
        const filesEl = $('ljk-batch-files');
        const cameraFileEl = $('ljk-batch-camera-file');
        const processBtn = $('ljk-batch-process');
        const finalizeBtn = $('ljk-batch-finalize');
        const clearBtn = $('ljk-batch-clear');
        const tbody = $('ljk-batch-body');
        const empty = $('ljk-batch-empty');
        const message = $('ljk-batch-message');
        const progress = $('ljk-batch-progress');
        const progressBar = $('ljk-batch-progress-bar');
        const progressText = $('ljk-batch-progress-text');
        const sumTotal = $('ljk-batch-total');
        const sumReady = $('ljk-batch-ready');
        const sumReview = $('ljk-batch-review');
        const sumFailed = $('ljk-batch-failed');
        const sumFinalized = $('ljk-batch-finalized');
        const editModal = $('ljk-batch-edit-modal');
        const editNisn = $('ljk-batch-edit-nisn');
        const editAnswers = $('ljk-batch-edit-answers');
        const editCanvas = $('ljk-batch-edit-canvas');
        const editTitle = $('ljk-batch-edit-title');
        const editSave = $('ljk-batch-edit-save');
        const editCancel = $('ljk-batch-edit-cancel');
        const cameraBtn = $('ljk-batch-camera-start');
        const cameraStopBtn = $('ljk-batch-camera-stop');
        const cameraPanel = $('ljk-batch-camera-panel');
        const cameraVideo = $('ljk-batch-camera-video');
        const cameraStatus = $('ljk-batch-camera-status');

        const MAX_BATCH_ITEMS = 200;

        const state = {
            items: [],
            seq: 0,
            batchUuid: makeUuid(),
            processing: false,
            editingId: null,
            cameraStream: null,
            cameraTimer: null,
            cameraArmed: true,
            cameraStable: 0,
            cameraMissing: 0,
        };

        function makeUuid() {
            if (global.crypto && typeof global.crypto.randomUUID === 'function') {
                return global.crypto.randomUUID();
            }
            return 'ljk-' + Date.now() + '-' + Math.random().toString(16).slice(2);
        }

        function blueprint() {
            return config.examConfigs[String(examEl.value)] || [];
        }

        function questionCount() {
            return blueprint().length;
        }

        function setMessage(text, type) {
            if (!message) return;
            message.className = 'rounded-lg border p-3 text-sm';
            if (type === 'error') message.classList.add('bg-red-50','border-red-200','text-red-800');
            else if (type === 'success') message.classList.add('bg-emerald-50','border-emerald-200','text-emerald-800');
            else message.classList.add('bg-blue-50','border-blue-200','text-blue-800');
            message.textContent = text;
            message.classList.remove('hidden');
        }

        function hideMessage() {
            if (message) message.classList.add('hidden');
        }

        function html(v) {
            return String(v ?? '')
                .replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')
                .replaceAll('"','&quot;').replaceAll("'",'&#039;');
        }

        function sourceLabel(item) {
            if (item.source === 'camera') return 'Kamera';
            if (item.source === 'pdf') return 'PDF';
            return 'Scanner';
        }

        function statusMeta(status) {
            return {
                queued: ['Menunggu', 'bg-gray-100 text-gray-700'],
                processing: ['Memproses', 'bg-blue-100 text-blue-800'],
                validating: ['Validasi', 'bg-blue-100 text-blue-800'],
                ready: ['Siap', 'bg-emerald-100 text-emerald-800'],
                need_review: ['Verifikasi', 'bg-amber-100 text-amber-900'],
                duplicate: ['Duplikat', 'bg-orange-100 text-orange-900'],
                failed: ['Gagal', 'bg-red-100 text-red-800'],
                ignored: ['Diabaikan', 'bg-gray-100 text-gray-500'],
                finalized: ['Final', 'bg-indigo-100 text-indigo-800'],
            }[status] || [status || '-', 'bg-gray-100 text-gray-700'];
        }

        function counts() {
            const active = state.items.filter(x => x.status !== 'ignored');
            return {
                total: active.length,
                ready: active.filter(x => x.status === 'ready').length,
                review: active.filter(x => ['need_review','duplicate'].includes(x.status)).length,
                failed: active.filter(x => x.status === 'failed').length,
                finalized: active.filter(x => x.status === 'finalized').length,
            };
        }

        function renderSummary() {
            const c = counts();
            sumTotal.textContent = c.total;
            sumReady.textContent = c.ready;
            sumReview.textContent = c.review;
            sumFailed.textContent = c.failed;
            sumFinalized.textContent = c.finalized;
            finalizeBtn.disabled = c.ready === 0 || state.processing;
            processBtn.disabled = state.items.filter(x => ['queued','failed'].includes(x.status) && x.file).length === 0 || state.processing || !examEl.value;
        }

        function rowActions(item) {
            if (item.status === 'duplicate') {
                return '<button class="ljk-batch-use text-xs font-semibold text-orange-700 hover:underline" data-id="' + html(item.id) + '">Gunakan</button>' +
                    '<button class="ljk-batch-edit ml-2 text-xs font-semibold text-indigo-700 hover:underline" data-id="' + html(item.id) + '">Periksa</button>';
            }
            if (['need_review','failed','ready'].includes(item.status)) {
                return '<button class="ljk-batch-edit text-xs font-semibold text-indigo-700 hover:underline" data-id="' + html(item.id) + '">Periksa</button>' +
                    '<button class="ljk-batch-remove ml-2 text-xs font-semibold text-red-600 hover:underline" data-id="' + html(item.id) + '">Hapus</button>';
            }
            if (item.status === 'queued') {
                return '<button class="ljk-batch-remove text-xs font-semibold text-red-600 hover:underline" data-id="' + html(item.id) + '">Hapus</button>';
            }
            return '';
        }

        function renderTable() {
            tbody.innerHTML = '';
            empty.classList.toggle('hidden', state.items.length > 0);

            state.items.forEach((item, idx) => {
                const [label, badge] = statusMeta(item.status);
                const tr = document.createElement('tr');
                tr.className = 'border-b border-gray-100 last:border-0';
                tr.innerHTML =
                    '<td class="px-3 py-2 text-xs text-gray-500">' + (idx + 1) + '</td>' +
                    '<td class="px-3 py-2"><div class="text-sm font-medium text-gray-800 max-w-[180px] truncate" title="' + html(item.sourceRef) + '">' + html(item.sourceRef) + '</div><div class="text-[11px] text-gray-500">' + sourceLabel(item) + '</div></td>' +
                    '<td class="px-3 py-2 font-mono text-sm tracking-wide">' + html(item.nisn || '-') + '</td>' +
                    '<td class="px-3 py-2"><div class="text-sm font-medium text-gray-800">' + html(item.studentName || '-') + '</div><div class="text-[11px] text-gray-500">' + html(item.homeroomName || '') + '</div></td>' +
                    '<td class="px-3 py-2 text-center font-semibold">' + (item.score == null ? '-' : html(item.score)) + '</td>' +
                    '<td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-1 text-[11px] font-semibold ' + badge + '">' + label + '</span><div class="text-[10px] text-gray-500 mt-1 max-w-[230px]">' + html(item.message || '') + '</div></td>' +
                    '<td class="px-3 py-2 whitespace-nowrap">' + rowActions(item) + '</td>';
                tbody.appendChild(tr);
            });

            tbody.querySelectorAll('.ljk-batch-edit').forEach(btn => btn.addEventListener('click', () => openEditor(btn.dataset.id)));
            tbody.querySelectorAll('.ljk-batch-remove').forEach(btn => btn.addEventListener('click', () => removeItem(btn.dataset.id)));
            tbody.querySelectorAll('.ljk-batch-use').forEach(btn => btn.addEventListener('click', () => chooseDuplicate(btn.dataset.id)));
            renderSummary();
        }

        function addImageFile(file, source, sourceRef) {
            if (state.items.length >= MAX_BATCH_ITEMS) {
                throw new Error('Satu batch dibatasi maksimum ' + MAX_BATCH_ITEMS + ' lembar agar memori browser tetap aman.');
            }
            const item = {
                id: 'b' + (++state.seq),
                file,
                source: source || 'scanner',
                sourceRef: sourceRef || file.name || ('lembar-' + state.seq),
                status: 'queued',
                nisn: '',
                answers: {},
                confidence: {},
                ambiguousCount: 0,
                reviewed: false,
                studentName: '',
                homeroomName: '',
                score: null,
                message: '',
                overlayCanvas: null,
                baseStatus: null,
            };
            state.items.push(item);
            return item;
        }

        async function loadScript(src) {
            return new Promise((resolve, reject) => {
                const old = document.querySelector('script[data-ljk-pdfjs="1"]');
                if (old && global.pdfjsLib) return resolve();
                const s = document.createElement('script');
                s.src = src;
                s.dataset.ljkPdfjs = '1';
                s.onload = resolve;
                s.onerror = () => reject(new Error('PDF.js lokal belum tersedia.'));
                document.head.appendChild(s);
            });
        }

        async function ensurePdfJs() {
            if (global.pdfjsLib) return global.pdfjsLib;
            await loadScript(config.pdfJsUrl || '/vendor/pdfjs/pdf.min.js');
            if (!global.pdfjsLib) throw new Error('PDF.js lokal tidak dapat dimuat.');
            global.pdfjsLib.GlobalWorkerOptions.workerSrc = config.pdfWorkerUrl || '/vendor/pdfjs/pdf.worker.min.js';
            return global.pdfjsLib;
        }

        async function expandPdf(file) {
            const lib = await ensurePdfJs();
            const data = new Uint8Array(await file.arrayBuffer());
            const doc = await lib.getDocument({ data }).promise;
            if (doc.numPages > 100) throw new Error('PDF terlalu besar. Maksimum 100 halaman per file PDF.');
            if (state.items.length + doc.numPages > MAX_BATCH_ITEMS) {
                throw new Error('Total batch akan melebihi ' + MAX_BATCH_ITEMS + ' lembar. Pecah menjadi batch baru.');
            }
            const created = [];
            for (let pageNo = 1; pageNo <= doc.numPages; pageNo++) {
                const page = await doc.getPage(pageNo);
                const base = page.getViewport({ scale: 1 });
                const scale = Math.min(3.0, Math.max(1.5, 1800 / Math.max(1, base.width)));
                const viewport = page.getViewport({ scale });
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(viewport.width);
                canvas.height = Math.round(viewport.height);
                const ctx = canvas.getContext('2d', { alpha: false, willReadFrequently: true });
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
                await page.render({ canvasContext: ctx, viewport }).promise;
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.95));
                if (!blob) throw new Error('Gagal merender halaman PDF ' + pageNo + '.');
                const image = new File([blob], file.name.replace(/\.pdf$/i, '') + '-hal-' + pageNo + '.jpg', { type: 'image/jpeg' });
                created.push(addImageFile(image, 'pdf', file.name + ' · halaman ' + pageNo));
            }
            return created;
        }

        async function addFiles(files, source) {
            hideMessage();
            const list = Array.from(files || []);
            if (!list.length) return;
            for (const file of list) {
                if (state.items.length >= MAX_BATCH_ITEMS) {
                    setMessage('Batas ' + MAX_BATCH_ITEMS + ' lembar tercapai. Finalisasi atau buat Batch Baru sebelum menambah LJK lagi.', 'error');
                    break;
                }
                if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '')) {
                    try {
                        setMessage('Merender PDF ' + file.name + ' secara lokal…');
                        await expandPdf(file);
                        setMessage('PDF berhasil dipecah menjadi halaman LJK.', 'success');
                    } catch (e) {
                        setMessage(e.message + ' Gunakan keluaran scanner multi-JPG/PNG, atau pasang PDF.js lokal dari tools paket LJK.', 'error');
                    }
                    continue;
                }
                if (!/^image\//.test(file.type || '')) continue;
                addImageFile(file, source || 'scanner', file.name || 'gambar-ljk');
            }
            renderTable();
        }

        async function runPool(items, limit, fn) {
            let cursor = 0;
            const workers = Array.from({ length: Math.max(1, Math.min(limit, items.length)) }, async () => {
                while (true) {
                    const i = cursor++;
                    if (i >= items.length) return;
                    await fn(items[i], i);
                }
            });
            await Promise.all(workers);
        }

        function updateProgress(done, total, text) {
            progress.classList.remove('hidden');
            const pct = total ? Math.round(done / total * 100) : 0;
            progressBar.style.width = pct + '%';
            progressText.textContent = text || (done + ' / ' + total);
        }

        async function processOne(item) {
            item.status = 'processing';
            item.message = 'Membaca marker, NISN, dan jawaban…';
            renderTable();
            try {
                const scan = await global.EduxidLjkOmr.analyze(item.file, blueprint());
                item.nisn = scan.nisn;
                item.answers = Object.assign({}, scan.answers || {});
                item.confidence = scan.confidence || {};
                item.ambiguousCount = Number(scan.ambiguousCount || 0);
                item.overlayCanvas = scan.overlayCanvas || null;
                item.status = 'validating';
                item.message = 'Menunggu validasi database…';
            } catch (e) {
                item.status = 'failed';
                item.message = 'OMR gagal: ' + e.message;
            }
        }

        function serverPayload(item) {
            return {
                client_id: item.id,
                nisn: item.nisn || '',
                source: item.source,
                source_ref: item.sourceRef,
                answers: item.answers || {},
                confidence: item.confidence || {},
                ambiguous_count: Number(item.ambiguousCount || 0),
            };
        }

        async function post(url, data) {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': config.csrf || '',
                },
                body: JSON.stringify(data),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || json.ok === false) {
                throw new Error(json.message || ('HTTP ' + response.status));
            }
            return json;
        }

        function applyValidation(row) {
            const item = state.items.find(x => x.id === row.client_id);
            if (!item) return;
            item.status = row.status || 'failed';
            item.baseStatus = item.status;
            item.message = row.message || '';
            item.studentName = row.student_name || '';
            item.homeroomName = row.homeroom_name || '';
            item.studentId = row.student_id || null;
            item.score = row.score ?? null;
            item.existingResult = !!row.existing_result;
            if (row.nisn) item.nisn = row.nisn;
        }

        function recomputeDuplicates() {
            const map = new Map();
            state.items.forEach(item => {
                if (['ignored','finalized'].includes(item.status)) return;
                if (!/^\d{10}$/.test(item.nisn || '')) return;
                if (!map.has(item.nisn)) map.set(item.nisn, []);
                map.get(item.nisn).push(item);
            });
            map.forEach(group => {
                if (group.length < 2) return;
                group.forEach(item => {
                    item.baseStatus = item.status === 'duplicate' ? (item.baseStatus || 'ready') : item.status;
                    item.status = 'duplicate';
                    item.message = 'NISN sama muncul ' + group.length + ' kali. Pilih lembar yang digunakan.';
                });
            });
        }

        function releaseResolvedHeavy(items) {
            (items || state.items).forEach(item => {
                if (item.status === 'ready') {
                    // Hasil valid tidak perlu menahan canvas penuh. File asli tetap ada
                    // agar guru masih dapat membuka lembar bila kemudian menjadi duplikat.
                    item.overlayCanvas = null;
                } else if (['finalized', 'ignored'].includes(item.status)) {
                    item.overlayCanvas = null;
                    item.file = null;
                }
            });
        }

        async function validateItems(items) {
            const usable = items.filter(x => x.status === 'validating');
            if (!usable.length) return;
            for (let start = 0; start < usable.length; start += 100) {
                const chunk = usable.slice(start, start + 100);
                const data = await post(config.batchPreviewUrl, {
                    exam_id: Number(examEl.value),
                    exam_date: dateEl.value,
                    items: chunk.map(serverPayload),
                });
                (data.rows || []).forEach(applyValidation);
            }
            recomputeDuplicates();
            releaseResolvedHeavy(state.items);
        }

        async function processAll() {
            hideMessage();
            if (!examEl.value) return setMessage('Pilih Paket Ujian terlebih dahulu.', 'error');
            if (!dateEl.value) return setMessage('Isi tanggal ujian.', 'error');
            if (!questionCount()) return setMessage('Paket Ujian tidak memiliki konfigurasi soal LJK.', 'error');

            const queue = state.items.filter(x => x.file && ['queued','failed'].includes(x.status));
            if (!queue.length) return setMessage('Tidak ada lembar yang menunggu diproses.', 'error');

            state.processing = true;
            renderSummary();
            updateProgress(0, queue.length, 'Memulai koreksi massal…');
            let done = 0;
            const concurrency = Math.max(1, Math.min(3, Number(global.navigator?.hardwareConcurrency || 2)));
            const memoryChunk = 12;

            try {
                for (let start = 0; start < queue.length; start += memoryChunk) {
                    const group = queue.slice(start, start + memoryChunk);
                    await runPool(group, concurrency, async item => {
                        await processOne(item);
                        done++;
                        updateProgress(done, queue.length, 'OMR ' + done + ' / ' + queue.length);
                    });

                    updateProgress(done, queue.length, 'Validasi kelompok ' + (Math.floor(start / memoryChunk) + 1) + '…');
                    await validateItems(group);
                    renderTable();
                }

                setMessage('Koreksi massal selesai. Periksa hanya baris Verifikasi/Duplikat/Gagal, lalu Finalisasi Massal.', 'success');
            } catch (e) {
                setMessage('Koreksi/validasi batch dihentikan: ' + e.message + '. Hasil kelompok yang sudah selesai tetap dipertahankan.', 'error');
            } finally {
                state.processing = false;
                progress.classList.add('hidden');
                renderTable();
            }
        }

        function collectAnswersFromEditor() {
            const out = {};
            editAnswers.querySelectorAll('[data-q]').forEach(el => {
                const q = el.dataset.q;
                if (el.type === 'checkbox') {
                    if (!(q in out)) out[q] = '';
                    if (el.checked) {
                        const a = out[q] ? out[q].split(',') : [];
                        a.push(el.value);
                        out[q] = [...new Set(a)].sort().join(',');
                    }
                } else {
                    out[q] = el.value;
                }
            });
            return out;
        }

        function renderEditorAnswers(item) {
            editAnswers.innerHTML = '';
            blueprint().forEach((q, idx) => {
                const n = Number(q.number || idx + 1);
                const current = String(item.answers?.[n] ?? '').split(',').filter(Boolean);
                const box = document.createElement('div');
                box.className = 'rounded-lg border border-gray-200 p-2';
                box.innerHTML = '<div class="text-xs font-semibold text-gray-700 mb-1">Soal ' + n + ' · ' + (q.type === 'majemuk' ? 'Majemuk' : q.type === 'benar_salah' ? 'B/S' : 'PG') + '</div>';
                if (q.type === 'majemuk') {
                    const wrap = document.createElement('div'); wrap.className = 'flex flex-wrap gap-1';
                    ['A','B','C','D','E'].forEach(letter => {
                        const lab = document.createElement('label'); lab.className = 'inline-flex items-center gap-1 border rounded px-2 py-1 text-xs';
                        const input = document.createElement('input'); input.type = 'checkbox'; input.value = letter; input.dataset.q = String(n); input.checked = current.includes(letter);
                        lab.append(input, document.createTextNode(letter)); wrap.appendChild(lab);
                    });
                    box.appendChild(wrap);
                } else {
                    const sel = document.createElement('select'); sel.dataset.q = String(n); sel.className = 'w-full rounded border-gray-300 text-sm py-1';
                    const choices = q.type === 'benar_salah' ? [['','Kosong'],['A','A · Benar'],['B','B · Salah']] : [['','Kosong'],['A','A'],['B','B'],['C','C'],['D','D'],['E','E']];
                    choices.forEach(([v,t]) => { const o = document.createElement('option'); o.value=v; o.textContent=t; o.selected=current[0]===v; sel.appendChild(o); });
                    box.appendChild(sel);
                }
                editAnswers.appendChild(box);
            });
        }

        async function drawOriginalToEditor(file) {
            if (!file) return false;
            const url = URL.createObjectURL(file);
            try {
                const img = new Image();
                await new Promise((resolve, reject) => {
                    img.onload = resolve;
                    img.onerror = () => reject(new Error('Pratinjau gambar tidak dapat dibuka.'));
                    img.src = url;
                });
                const maxWidth = 1200;
                const scale = Math.min(1, maxWidth / Math.max(1, img.naturalWidth));
                editCanvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
                editCanvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
                editCanvas.getContext('2d').drawImage(img, 0, 0, editCanvas.width, editCanvas.height);
                return true;
            } finally {
                URL.revokeObjectURL(url);
            }
        }

        async function openEditor(id) {
            const item = state.items.find(x => x.id === id);
            if (!item) return;
            state.editingId = id;
            editTitle.textContent = 'Periksa LJK · ' + item.sourceRef;
            editNisn.value = (item.nisn || '').replace(/\?/g, '');
            renderEditorAnswers(item);
            if (item.overlayCanvas) {
                editCanvas.width = item.overlayCanvas.width;
                editCanvas.height = item.overlayCanvas.height;
                editCanvas.getContext('2d').drawImage(item.overlayCanvas, 0, 0);
                editCanvas.classList.remove('hidden');
            } else if (item.file) {
                try {
                    await drawOriginalToEditor(item.file);
                    editCanvas.classList.remove('hidden');
                } catch (_) {
                    editCanvas.classList.add('hidden');
                }
            } else {
                editCanvas.classList.add('hidden');
            }
            editModal.classList.remove('hidden');
        }

        function closeEditor() {
            editModal.classList.add('hidden');
            state.editingId = null;
        }

        async function saveEditor() {
            const item = state.items.find(x => x.id === state.editingId);
            if (!item) return closeEditor();
            item.nisn = editNisn.value.trim();
            item.answers = collectAnswersFromEditor();
            item.reviewed = true;
            item.status = 'validating';
            item.message = 'Memvalidasi hasil koreksi guru…';
            renderTable();
            try {
                const data = await post(config.batchPreviewUrl, {
                    exam_id: Number(examEl.value), exam_date: dateEl.value, items: [serverPayload(item)]
                });
                const row = (data.rows || [])[0];
                if (row) {
                    applyValidation(row);
                    if (item.status === 'need_review') {
                        item.status = 'ready';
                        item.message = 'Sudah diverifikasi manual oleh guru. Siap difinalisasi.';
                    }
                    recomputeDuplicates();
                    releaseResolvedHeavy(state.items);
                }
                closeEditor();
                renderTable();
            } catch (e) {
                item.status = 'failed'; item.message = e.message; renderTable();
            }
        }

        async function revalidateSingletonDuplicates() {
            const counts = new Map();
            state.items.forEach(item => {
                if (['ignored','finalized'].includes(item.status) || !/^\d{10}$/.test(item.nisn || '')) return;
                counts.set(item.nisn, (counts.get(item.nisn) || 0) + 1);
            });
            const singles = state.items.filter(item => item.status === 'duplicate' && (counts.get(item.nisn) || 0) === 1);
            singles.forEach(item => { item.status = 'validating'; item.message = 'Memvalidasi ulang setelah duplikat dihapus…'; });
            if (singles.length) {
                try { await validateItems(singles); } catch (e) {
                    singles.forEach(item => { item.status = 'failed'; item.message = e.message; });
                }
            }
        }

        function removeItem(id) {
            const idx = state.items.findIndex(x => x.id === id);
            if (idx >= 0) state.items.splice(idx, 1);
            revalidateSingletonDuplicates().finally(() => { recomputeDuplicates(); renderTable(); });
            renderTable();
        }

        async function chooseDuplicate(id) {
            const chosen = state.items.find(x => x.id === id);
            if (!chosen || !chosen.nisn) return;
            state.items.forEach(item => {
                if (item.id !== id && item.nisn === chosen.nisn && item.status === 'duplicate') {
                    item.status = 'ignored'; item.message = 'Diabaikan karena guru memilih lembar duplikat lain.';
                    item.overlayCanvas = null; item.file = null;
                }
            });
            chosen.status = 'validating';
            try {
                const data = await post(config.batchPreviewUrl, {
                    exam_id: Number(examEl.value), exam_date: dateEl.value, items: [serverPayload(chosen)]
                });
                const row = (data.rows || [])[0];
                if (row) applyValidation(row);
                releaseResolvedHeavy(state.items);
                renderTable();
            } catch (e) {
                chosen.status = 'failed'; chosen.message = e.message; renderTable();
            }
        }

        async function finalizeAll() {
            const ready = state.items.filter(x => x.status === 'ready');
            if (!ready.length) return setMessage('Belum ada nilai berstatus Siap.', 'error');
            state.processing = true;
            renderSummary();
            hideMessage();
            let done = 0;
            updateProgress(0, ready.length, 'Menyimpan Buku Nilai…');
            try {
                for (let start = 0; start < ready.length; start += 50) {
                    const chunk = ready.slice(start, start + 50);
                    const data = await post(config.batchFinalizeUrl, {
                        exam_id: Number(examEl.value),
                        exam_date: dateEl.value,
                        batch_uuid: state.batchUuid,
                        batch_total: state.items.filter(x => x.status !== 'ignored').length,
                        items: chunk.map(item => Object.assign(serverPayload(item), {
                            confirmed_review: item.reviewed || Number(item.ambiguousCount || 0) === 0,
                        })),
                    });
                    const results = data.result?.results || [];
                    results.forEach(r => {
                        const item = state.items.find(x => x.id === r.client_id);
                        if (!item) return;
                        item.status = 'finalized'; item.score = r.score; item.studentName = r.student_name; item.homeroomName = r.homeroom_name; item.message = 'Nilai sudah masuk Buku Nilai.';
                        item.overlayCanvas = null; item.file = null;
                    });
                    done += chunk.length;
                    updateProgress(done, ready.length, 'Finalisasi ' + done + ' / ' + ready.length);
                    renderTable();
                }
                setMessage(done + ' nilai berhasil difinalisasi massal ke Buku Nilai. Sinkronisasi PWA Guru mengikuti antrean eduXid.', 'success');
            } catch (e) {
                setMessage('Finalisasi massal dihentikan: ' + e.message, 'error');
            } finally {
                state.processing = false;
                progress.classList.add('hidden');
                renderTable();
            }
        }

        function clearBatch() {
            if (state.processing) return;
            state.items = [];
            state.batchUuid = makeUuid();
            filesEl.value = ''; cameraFileEl.value = '';
            hideMessage(); renderTable();
        }

        async function startCameraBurst() {
            if (!navigator.mediaDevices?.getUserMedia) {
                return setMessage('Kamera beruntun tidak tersedia pada browser/koneksi ini. Gunakan tombol Tambah Foto Kamera.', 'error');
            }
            try {
                state.cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                cameraVideo.srcObject = state.cameraStream;
                await cameraVideo.play();
                cameraPanel.classList.remove('hidden');
                cameraBtn.classList.add('hidden'); cameraStopBtn.classList.remove('hidden');
                state.cameraArmed = true; state.cameraStable = 0; state.cameraMissing = 0;
                state.cameraTimer = setInterval(cameraTick, 750);
                cameraStatus.textContent = 'Arahkan kamera hingga empat marker terlihat.';
            } catch (e) {
                setMessage('Kamera tidak dapat dibuka: ' + e.message + '. Pada akses LAN HTTP, gunakan Ambil Foto Kamera.', 'error');
            }
        }

        function stopCameraBurst() {
            if (state.cameraTimer) clearInterval(state.cameraTimer);
            state.cameraTimer = null;
            if (state.cameraStream) state.cameraStream.getTracks().forEach(t => t.stop());
            state.cameraStream = null;
            cameraVideo.srcObject = null;
            cameraPanel.classList.add('hidden');
            cameraBtn.classList.remove('hidden'); cameraStopBtn.classList.add('hidden');
        }

        async function cameraTick() {
            if (!state.cameraStream || cameraVideo.readyState < 2 || state.processing) return;
            const c = document.createElement('canvas');
            const scale = Math.min(1, 1000 / cameraVideo.videoWidth);
            c.width = Math.round(cameraVideo.videoWidth * scale);
            c.height = Math.round(cameraVideo.videoHeight * scale);
            c.getContext('2d', { willReadFrequently: true }).drawImage(cameraVideo, 0, 0, c.width, c.height);
            const check = global.EduxidLjkOmr.markerCheck(c);
            if (check.detected) {
                state.cameraMissing = 0;
                state.cameraStable++;
                cameraStatus.textContent = state.cameraArmed ? 'Marker stabil ' + state.cameraStable + '/3…' : 'Lembar sudah diambil. Angkat kertas untuk melanjutkan.';
                if (state.cameraArmed && state.cameraStable >= 3) {
                    state.cameraArmed = false; state.cameraStable = 0;
                    const full = document.createElement('canvas');
                    const maxW = 1800, s = Math.min(1, maxW / cameraVideo.videoWidth);
                    full.width = Math.round(cameraVideo.videoWidth * s); full.height = Math.round(cameraVideo.videoHeight * s);
                    full.getContext('2d').drawImage(cameraVideo, 0, 0, full.width, full.height);
                    const blob = await new Promise(resolve => full.toBlob(resolve, 'image/jpeg', 0.95));
                    if (blob) {
                        if (state.items.length >= MAX_BATCH_ITEMS) {
                            cameraStatus.textContent = 'Batas ' + MAX_BATCH_ITEMS + ' lembar tercapai. Finalisasi atau buat Batch Baru.';
                            stopCameraBurst();
                            return;
                        }
                        const name = 'kamera-' + new Date().toISOString().replace(/[:.]/g,'-') + '.jpg';
                        const item = addImageFile(new File([blob], name, { type: 'image/jpeg' }), 'camera', name);
                        renderTable();
                        await processOne(item);
                        await validateItems([item]);
                        recomputeDuplicates(); renderTable();
                    }
                }
            } else {
                state.cameraStable = 0;
                if (!state.cameraArmed) {
                    state.cameraMissing++;
                    if (state.cameraMissing >= 2) {
                        state.cameraArmed = true; state.cameraMissing = 0;
                        cameraStatus.textContent = 'Siap untuk lembar berikutnya.';
                    }
                } else {
                    cameraStatus.textContent = 'Empat marker belum terdeteksi.';
                }
            }
        }

        let lockedExamValue = examEl.value;
        let lockedDateValue = dateEl.value;

        function guardBatchContextChange(element, previousValue, label) {
            if (state.processing) {
                element.value = previousValue;
                setMessage('Batch sedang diproses. Selesaikan proses sebelum mengganti ' + label + '.', 'error');
                return previousValue;
            }
            if (state.items.length > 0) {
                const ok = global.confirm('Mengganti ' + label + ' akan membersihkan seluruh antrean LJK pada batch ini. Lanjutkan?');
                if (!ok) {
                    element.value = previousValue;
                    return previousValue;
                }
                stopCameraBurst();
                clearBatch();
            }
            return element.value;
        }

        examEl.addEventListener('change', () => {
            lockedExamValue = guardBatchContextChange(examEl, lockedExamValue, 'Paket Ujian');
            renderSummary();
        });
        dateEl.addEventListener('change', () => {
            lockedDateValue = guardBatchContextChange(dateEl, lockedDateValue, 'tanggal ujian');
            renderSummary();
        });

        filesEl.addEventListener('change', () => addFiles(filesEl.files, 'scanner'));
        cameraFileEl.addEventListener('change', () => addFiles(cameraFileEl.files, 'camera'));
        processBtn.addEventListener('click', processAll);
        finalizeBtn.addEventListener('click', finalizeAll);
        clearBtn.addEventListener('click', clearBatch);
        editSave.addEventListener('click', saveEditor);
        editCancel.addEventListener('click', closeEditor);
        cameraBtn.addEventListener('click', startCameraBurst);
        cameraStopBtn.addEventListener('click', stopCameraBurst);
        examEl.addEventListener('change', () => { state.items.forEach(x => { if (x.status !== 'finalized') x.status = 'queued'; }); renderTable(); });
        dateEl.addEventListener('change', () => { state.items.forEach(x => { if (x.status !== 'finalized') x.status = 'queued'; }); renderTable(); });
        global.addEventListener('beforeunload', stopCameraBurst);

        renderTable();
    }

    global.EduxidLjkBatch = { init };
})(window);
