(() => {
  'use strict';

  const VERSION = '13R11R1-20261006';
  const root = document.getElementById('ljk-laptop-v13r11');
  if (!root) return;

  const CORE = window.EDUXID_LJK_SCANNER_V13R11 || window.EDUXID_LJK_SCANNER_V13R10 || window.EDUXID_LJK_SCANNER_V13R9;
  const $ = s => root.querySelector(s);
  const examSelect = $('#ljk-exam'), fileInput = $('#ljk-file');
  const cameraBtn = $('#ljk-camera'), galleryBtn = $('#ljk-gallery'), processBtn = $('#ljk-process');
  const syncBtn = $('#ljk-sync-private'), saveBtn = $('#ljk-save');
  const preview = $('#ljk-preview'), canvas = $('#ljk-canvas'), statusBox = $('#ljk-status');
  const packageInfo = $('#ljk-package-info'), threshold = $('#ljk-threshold');
  const thresholdValue = $('#ljk-threshold-value'), effectiveThreshold = $('#ljk-effective-threshold');
  const resultBox = $('#ljk-result'), summary = $('#ljk-summary'), body = $('#ljk-result-body'), warning = $('#ljk-review-warning');
  const packageBadge = $('#ljk-package-badge'), photoBadge = $('#ljk-photo-badge');
  const selectedFile = $('#ljk-selected-file'), previewPlaceholder = $('#ljk-preview-placeholder'), canvasPlaceholder = $('#ljk-canvas-placeholder');
  const cameraModal = $('#ljk-camera-modal'), cameraVideo = $('#ljk-camera-video'), cameraCapture = $('#ljk-camera-capture');
  const cameraClose = $('#ljk-camera-close'), cameraCancel = $('#ljk-camera-cancel'), cameraHint = $('#ljk-camera-hint');
  const csrf = String(root.dataset.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '');

  let pkg = null, file = null, objectUrl = null, lastResult = null, cameraStream = null;

  function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function urlFrom(t, k, v) { return String(t || '').replace(k, encodeURIComponent(String(v))); }
  const packageUrl = id => urlFrom(root.dataset.packageUrlTemplate, '__EXAM__', id);
  const commitUrl = id => urlFrom(root.dataset.commitUrlTemplate, '__EXAM__', id);
  const syncUrl = id => urlFrom(root.dataset.syncUrlTemplate, '__EXAM__', id);
  const studentUrl = nisn => urlFrom(root.dataset.studentUrlTemplate, '__NISN__', nisn);

  function setStatus(text, type='') {
    statusBox.textContent = text;
    statusBox.className = 'mt-3 rounded-xl border p-3.5 text-sm ' + (type === 'ok'
      ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : type === 'err'
      ? 'bg-red-50 border-red-200 text-red-800' : type === 'warn'
      ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-slate-50 border-slate-200 text-slate-700');
  }

  function setPackageBadge(text, ok=false) {
    if (!packageBadge) return;
    packageBadge.textContent = text;
    packageBadge.className = 'rounded-full px-2.5 py-1 text-xs font-semibold ' + (ok ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200' : 'bg-slate-100 text-slate-600');
  }
  function setPhotoBadge(text, ok=false) {
    if (!photoBadge) return;
    photoBadge.textContent = text;
    photoBadge.className = 'rounded-full px-2.5 py-1 text-xs font-semibold ' + (ok ? 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200' : 'bg-slate-100 text-slate-600');
  }

  if (!CORE) {
    console.error('[eduXid LJK Laptop v13R11] Scanner core tidak tersedia.');
    setStatus('Mesin Pemindai LJK v13R11 gagal dimuat. Tutup browser, buka kembali, lalu coba lagi.', 'err');
    return;
  }

  function normalizeNisn(value) {
    let d = String(value ?? '').replace(/\D/g, '');
    if (!d) return '';
    if (d.length < 10) d = d.padStart(10, '0');
    if (d.length > 10) d = d.slice(-10);
    return d;
  }

  function localStudent(nisn) {
    const want = normalizeNisn(nisn);
    const rows = [...(pkg?.students || []), ...(pkg?.roster || [])];
    for (const s of rows) {
      if (normalizeNisn(s?.nisn ?? s?.student_nisn ?? '') !== want) continue;
      return {
        student_id: s?.student_id ?? s?.id ?? null,
        nisn: want,
        name: String(s?.name ?? s?.nama ?? s?.student_name ?? '').trim(),
        homeroom_id: s?.homeroom_id ?? null
      };
    }
    return null;
  }

  async function resolveStudent(nisn) {
    const want = normalizeNisn(nisn);
    if (!/^\d{10}$/.test(want)) return null;
    const local = localStudent(want);
    if (local?.name) return local;
    try {
      const r = await fetch(studentUrl(want), { headers:{Accept:'application/json'}, cache:'no-store' });
      const j = await r.json();
      if (r.ok && j?.success && j?.data) return { ...j.data, nisn: normalizeNisn(j.data.nisn || want), name: j.data.name || j.data.nama || '' };
    } catch (_) {}
    return local;
  }

  function gradeRows(answers) {
    const qs = pkg?.questions || [];
    let correct = 0, graded = 0, invalid = 0, review = 0, totalWeight = 0, earnedWeight = 0;
    const rows = answers.map((a, i) => {
      const q = qs[i] || { no:i+1, type:'', key:[], weight:1 };
      const cmp = CORE.compareAnswer(a.selected, q.key, q.type);
      const weight = Math.max(0, Number(q.weight ?? 1) || 1);
      if (a.ambiguous) review++;
      if (cmp.invalid) invalid++;
      if (cmp.k.length) {
        graded++; totalWeight += weight;
        if (cmp.ok) { correct++; earnedWeight += weight; }
      }
      return { ...cmp, no:i+1, type:q.type || '', answer:a.value || '', key:cmp.k.join(','), weight, ambiguous:!!a.ambiguous, blank:!!a.blank, near:a.near || [], scores:a.scores || [] };
    });
    const score = totalWeight > 0 ? Math.round((earnedWeight / totalWeight) * 10000) / 100 : 0;
    return { rows, correct, graded, invalid, review, score, totalWeight, earnedWeight };
  }

  function enableAfterExamChosen(enabled) {
    syncBtn.disabled = !enabled;
    cameraBtn.disabled = !enabled;
    galleryBtn.disabled = !enabled;
    processBtn.disabled = !(enabled && file && pkg);
  }

  async function loadPackage() {
    const id = String(examSelect.value || '').trim();
    pkg = null; lastResult = null; resultBox.classList.add('hidden');
    setPackageBadge(id ? 'Memuat…' : 'Belum dipilih');
    enableAfterExamChosen(!!id);
    if (!id) {
      packageInfo.classList.add('hidden');
      setStatus('Pilih Paket Ujian terlebih dahulu.');
      return;
    }

    const selected = examSelect.options[examSelect.selectedIndex];
    setStatus(`Memuat paket ${selected?.dataset?.title || selected?.textContent || id}…`);
    try {
      const r = await fetch(packageUrl(id), { headers:{Accept:'application/json'}, cache:'no-store' });
      const j = await r.json();
      if (!r.ok || !j.success) throw new Error(j.message || 'Gagal memuat paket privat.');
      pkg = j.data;
      const keyCount = Number(pkg.key_count ?? (pkg.answer_keys || pkg.kunci_jawaban || []).filter?.(k => Array.isArray(k) ? k.length : !!k)?.length ?? 0);
      const studentCount = Number(pkg.student_count ?? pkg.students?.length ?? pkg.roster?.length ?? 0);
      packageInfo.innerHTML = `<b>${escapeHtml(pkg.title || selected?.dataset?.title || 'Paket Ujian')}</b><div class="mt-1 text-xs">${Number(pkg.question_count || 0)} soal · <b>${keyCount}</b> kunci · <b>${studentCount}</b> siswa · Template ${escapeHtml(pkg.template_id || 'LJK-A4-V3')}</div>`;
      packageInfo.classList.remove('hidden');
      setPackageBadge('Paket siap', true);
      enableAfterExamChosen(true);
      setStatus(`Paket siap: ${keyCount} kunci dan ${studentCount} siswa. Tombol kamera, pilih foto, dan kirim paket privat sudah aktif.`, keyCount > 0 ? 'ok' : 'warn');
    } catch (e) {
      packageInfo.classList.add('hidden');
      setPackageBadge('Gagal dimuat');
      setStatus('Gagal memuat paket privat: ' + (e?.message || e), 'err');
      // Tombol kirim tetap aktif karena endpoint hanya membutuhkan exam id.
      syncBtn.disabled = false;
      cameraBtn.disabled = galleryBtn.disabled = false;
    }
  }

  examSelect.addEventListener('change', () => { loadPackage().catch(e => setStatus(String(e?.message || e), 'err')); });

  function setPhoto(newFile, label='Foto siap') {
    if (!newFile) return;
    file = newFile;
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = URL.createObjectURL(file);
    preview.src = objectUrl;
    preview.classList.remove('hidden');
    previewPlaceholder?.classList.add('hidden');
    selectedFile.textContent = `${file.name || 'Foto kamera'} · ${Math.max(1, Math.round((file.size || 0) / 1024))} KB`;
    selectedFile.classList.remove('hidden');
    setPhotoBadge('Foto siap', true);
    processBtn.disabled = !pkg;
    setStatus(pkg ? `${label}. Tekan Baca LJK.` : `${label}. Menunggu paket ujian selesai dimuat.`, pkg ? 'ok' : 'warn');
  }

  galleryBtn.addEventListener('click', () => {
    fileInput.removeAttribute('capture');
    fileInput.value = '';
    fileInput.click();
  });
  fileInput.addEventListener('change', () => setPhoto(fileInput.files?.[0] || null, 'Foto dipilih'));

  function stopCamera() {
    if (cameraStream) {
      for (const track of cameraStream.getTracks()) track.stop();
      cameraStream = null;
    }
    cameraVideo.srcObject = null;
    cameraModal.classList.add('hidden');
    cameraModal.classList.remove('flex');
    cameraModal.setAttribute('aria-hidden','true');
  }

  async function openCamera() {
    if (!examSelect.value) { setStatus('Pilih Paket Ujian terlebih dahulu.', 'warn'); return; }
    if (!navigator.mediaDevices?.getUserMedia) {
      fileInput.setAttribute('capture','environment');
      fileInput.value = '';
      fileInput.click();
      setStatus('Browser tidak menyediakan webcam langsung. Pemilih kamera perangkat dibuka sebagai fallback.', 'warn');
      return;
    }
    try {
      cameraHint.textContent = 'Meminta akses kamera…';
      cameraModal.classList.remove('hidden');
      cameraModal.classList.add('flex');
      cameraModal.setAttribute('aria-hidden','false');
      cameraStream = await navigator.mediaDevices.getUserMedia({
        audio:false,
        video:{ width:{ideal:1920}, height:{ideal:1080}, facingMode:{ideal:'environment'} }
      });
      cameraVideo.srcObject = cameraStream;
      await cameraVideo.play().catch(()=>{});
      cameraHint.textContent = 'Pastikan seluruh kertas dan empat marker terlihat, lalu tekan Ambil Foto.';
    } catch (e) {
      stopCamera();
      setStatus('Kamera laptop tidak dapat dibuka: ' + (e?.message || e) + '. Gunakan Pilih Foto sebagai alternatif.', 'err');
    }
  }

  cameraBtn.addEventListener('click', () => { openCamera().catch(e => setStatus(String(e?.message || e), 'err')); });
  cameraClose.addEventListener('click', stopCamera);
  cameraCancel.addEventListener('click', stopCamera);
  cameraModal.addEventListener('click', e => { if (e.target === cameraModal) stopCamera(); });

  cameraCapture.addEventListener('click', async () => {
    if (!cameraVideo.videoWidth || !cameraVideo.videoHeight) { setStatus('Kamera belum siap mengambil gambar.', 'warn'); return; }
    const c = document.createElement('canvas');
    c.width = cameraVideo.videoWidth; c.height = cameraVideo.videoHeight;
    c.getContext('2d', {alpha:false}).drawImage(cameraVideo, 0, 0, c.width, c.height);
    const blob = await new Promise(resolve => c.toBlob(resolve, 'image/jpeg', 0.94));
    if (!blob) { setStatus('Gagal mengambil foto dari kamera.', 'err'); return; }
    const f = typeof File === 'function' ? new File([blob], `LJK-${Date.now()}.jpg`, {type:'image/jpeg'}) : blob;
    if (!f.name) try { Object.defineProperty(f, 'name', {value:`LJK-${Date.now()}.jpg`}); } catch (_) {}
    stopCamera();
    setPhoto(f, 'Foto kamera berhasil diambil');
  });

  threshold.addEventListener('input', () => thresholdValue.textContent = threshold.value);

  syncBtn.addEventListener('click', async () => {
    const id = String(examSelect.value || pkg?.exam_id || '').trim();
    if (!id) { setStatus('Pilih Paket Ujian terlebih dahulu.', 'warn'); return; }
    syncBtn.disabled = true;
    setStatus('Mengirim paket privat berisi kunci + roster ke PWA Guru…');
    try {
      const r = await fetch(syncUrl(id), { method:'POST', headers:{'X-CSRF-TOKEN':csrf,Accept:'application/json'}, cache:'no-store' });
      const j = await r.json().catch(() => null);
      if (!r.ok || !j?.success) throw new Error(j?.message || 'Sinkronisasi paket privat gagal.');
      setStatus(j.message || 'Paket privat berhasil diarahkan ke PWA Guru.', 'ok');
    } catch (e) {
      setStatus('Paket privat belum terkirim: ' + (e?.message || e), 'err');
    } finally {
      syncBtn.disabled = false;
    }
  });

  function copyCanvas(src, dst) {
    dst.width = src.width; dst.height = src.height;
    dst.getContext('2d').drawImage(src, 0, 0);
    dst.classList.remove('hidden');
    canvasPlaceholder?.classList.add('hidden');
  }

  async function process() {
    if (!pkg) { setStatus('Paket ujian belum siap.', 'warn'); return; }
    if (!file) { setStatus('Ambil atau pilih foto LJK terlebih dahulu.', 'warn'); return; }
    processBtn.disabled = true; saveBtn.disabled = true; delete saveBtn.dataset.saved; saveBtn.textContent = 'Simpan Nilai'; resultBox.classList.add('hidden');
    try {
      const scan = await CORE.scanFile(file, {
        minThreshold: Number(threshold.value || 38),
        questionCount: Number(pkg.question_count || pkg.questions?.length || 50),
        maxSide: 2600,
        previewScale: 0.5,
        onStage: (_stage, text) => setStatus(text)
      });
      if (scan.previewCanvas) copyCanvas(scan.previewCanvas, canvas);
      effectiveThreshold.textContent = String(Math.round(scan.threshold * 10) / 10);
      const student = await resolveStudent(scan.nisn.nisn);
      const grade = gradeRows(scan.answers);
      lastResult = { ...scan, student, grade };
      renderResult();

      const problems = [];
      if (!student) problems.push(`NISN ${scan.nisn.nisn} belum cocok dengan roster.`);
      if (scan.nisn.ambiguousCount) problems.push(`${scan.nisn.ambiguousCount} digit NISN perlu review.`);
      if (grade.review) problems.push(`${grade.review} jawaban perlu review.`);
      if (grade.invalid) problems.push(`${grade.invalid} PG memiliki isian ganda.`);
      if (problems.length) setStatus('Pemindaian selesai: ' + problems.join(' '), 'warn');
      else setStatus(`Pemindaian selesai: ${student.name} (${scan.nisn.nisn}) · nilai sementara ${grade.score}.`, 'ok');
    } catch (e) {
      console.error('[eduXid LJK Laptop v13R11]', e);
      setStatus('Proses koreksi gagal: ' + (e?.message || e), 'err');
    } finally {
      processBtn.disabled = false;
    }
  }

  function statusCell(r) {
    if (r.ambiguous) return '<span class="font-semibold text-amber-700">Perlu review</span>';
    if (r.invalid) return '<span class="font-semibold text-amber-700">Ganda tidak valid</span>';
    if (r.blank) return '<span class="font-semibold text-slate-500">Kosong</span>';
    return r.ok ? '<span class="font-semibold text-emerald-700">Benar</span>' : '<span class="font-semibold text-red-700">Salah</span>';
  }

  function validationProblems() {
    const problems = [];
    const g = lastResult?.grade;
    const nisn = normalizeNisn(lastResult?.nisn?.nisn || '');
    if (!lastResult) problems.push('Belum ada hasil pemindaian.');
    if (!/^\d{10}$/.test(nisn)) problems.push('NISN belum lengkap 10 digit.');
    if (!lastResult?.student) problems.push('Nama siswa belum ditemukan dari NISN.');
    if (lastResult?.nisn?.ambiguousCount || String(lastResult?.nisn?.nisn || '').includes('?')) problems.push('NISN masih perlu review.');
    const reviewNos = (g?.rows || []).filter(r => r.ambiguous).map(r => r.no);
    const invalidNos = (g?.rows || []).filter(r => r.invalid).map(r => r.no);
    if (reviewNos.length) problems.push(`Jawaban perlu review pada nomor: ${reviewNos.join(', ')}.`);
    if (invalidNos.length) problems.push(`PG biasa berisi lebih dari satu pilihan pada nomor: ${invalidNos.join(', ')}.`);
    return problems;
  }

  function updateSaveButtonState() {
    if (!saveBtn) return;
    const ready = !!(lastResult && pkg);
    saveBtn.disabled = !ready;
    saveBtn.title = ready ? 'Validasi hasil lalu simpan nilai resmi' : 'Lakukan pemindaian terlebih dahulu';
  }

  async function readJsonResponse(response) {
    const text = await response.text();
    if (!text) return {};
    try { return JSON.parse(text); }
    catch (_) {
      if (response.status === 419) throw new Error('Sesi keamanan (CSRF) kedaluwarsa. Muat ulang halaman lalu coba Simpan Nilai lagi.');
      if (response.redirected || /<html|<!doctype/i.test(text)) throw new Error('Sesi login berubah atau respons server bukan JSON. Muat ulang halaman Koreksi LJK.');
      throw new Error(`Respons server tidak dapat dibaca (HTTP ${response.status}).`);
    }
  }

  function renderResult() {
    if (!lastResult) return;
    const g = lastResult.grade, s = lastResult.student;
    summary.innerHTML = `
      <div class="rounded-xl border bg-white p-3"><small class="text-slate-500">NISN</small><input id="ljk-nisn-edit" class="mt-1 block w-full rounded-lg border-slate-300 text-sm font-bold" maxlength="10" value="${escapeHtml(lastResult.nisn.nisn.replace(/\?/g,''))}" placeholder="10 digit NISN"></div>
      <div class="rounded-xl border bg-white p-3"><small class="text-slate-500">Nama Siswa</small><b class="block text-base text-slate-800">${escapeHtml(s?.name || 'Tidak ditemukan')}</b></div>
      <div class="rounded-xl border bg-white p-3"><small class="text-slate-500">Nilai</small><b class="block text-lg">${g.score}</b></div>
      <div class="rounded-xl border bg-white p-3"><small class="text-slate-500">Benar</small><b class="block text-lg">${g.correct}/${g.graded}</b></div>
      <div class="rounded-xl border bg-white p-3"><small class="text-slate-500">Marker</small><b class="block text-lg">${Math.round(lastResult.markerConfidence*100)}%</b></div>`;

    body.innerHTML = g.rows.map((r, i) => `<tr data-row="${i}" class="${r.ambiguous ? 'bg-amber-50' : ''}">
      <td class="p-2.5 text-center">${i+1}</td>
      <td class="p-2.5 text-center">${escapeHtml(r.type)}</td>
      <td class="p-2.5"><input class="ljk-answer-edit w-28 rounded-lg border-slate-300 text-sm" data-i="${i}" value="${escapeHtml(r.answer)}" placeholder="Kosong / A,B"><div class="mt-1 text-[10px] text-slate-400">${r.near.length ? 'Hampir terisi: '+escapeHtml(r.near.join(',')) : ''}</div></td>
      <td class="p-2.5 text-center font-semibold text-slate-700">${escapeHtml(r.key)}</td>
      <td class="p-2.5 text-center">${statusCell(r)}</td>
    </tr>`).join('');

    summary.querySelector('#ljk-nisn-edit')?.addEventListener('change', async e => {
      const nisn = normalizeNisn(e.target.value);
      e.target.value = nisn;
      lastResult.nisn.nisn = nisn.length === 10 ? nisn : nisn.padEnd(10, '?');
      lastResult.nisn.ambiguousCount = nisn.length === 10 ? 0 : 10 - nisn.length;
      lastResult.student = await resolveStudent(nisn);
      renderResult();
    });

    body.querySelectorAll('.ljk-answer-edit').forEach(input => input.addEventListener('change', () => {
      const i = Number(input.dataset.i), set = CORE.normalizeSet(input.value);
      lastResult.answers[i].selected = set;
      lastResult.answers[i].value = set.join(',');
      lastResult.answers[i].near = [];
      lastResult.answers[i].ambiguous = false;
      lastResult.answers[i].blank = set.length === 0;
      lastResult.answers[i].manual = true;
      lastResult.grade = gradeRows(lastResult.answers);
      renderResult();
    }));

    const problems = validationProblems();
    warning.textContent = problems.join(' ');
    warning.classList.toggle('hidden', problems.length === 0);
    resultBox.classList.remove('hidden');
    updateSaveButtonState();
  }

  processBtn.addEventListener('click', e => { e.preventDefault(); process().catch(err => setStatus('Baca LJK gagal: '+(err?.message || err), 'err')); });

  saveBtn.addEventListener('click', async (event) => {
    event.preventDefault();
    if (!lastResult || !pkg) {
      setStatus('Belum ada hasil LJK yang dapat disimpan. Tekan Baca LJK terlebih dahulu.', 'warn');
      return;
    }

    const problems = validationProblems();
    if (problems.length) {
      setStatus('Nilai belum dapat disimpan. ' + problems.join(' '), 'warn');
      warning.textContent = problems.join(' ');
      warning.classList.remove('hidden');
      resultBox.scrollIntoView({behavior:'smooth', block:'start'});
      return;
    }

    const examId = String(pkg.exam_id || examSelect.value || '').trim();
    if (!examId) {
      setStatus('ID Paket Ujian tidak tersedia. Pilih ulang paket ujian.', 'err');
      return;
    }
    if (!csrf) {
      setStatus('Token CSRF tidak tersedia. Muat ulang halaman sebelum menyimpan nilai.', 'err');
      return;
    }

    const nisn = normalizeNisn(lastResult.nisn.nisn);
    const answers = lastResult.answers.map(a => CORE.normalizeSet(a.selected));
    saveBtn.disabled = true;
    const previousText = saveBtn.textContent;
    saveBtn.textContent = 'Menyimpan…';
    setStatus('Menyimpan nilai melalui koreksi CBT resmi…');

    try {
      const r = await fetch(commitUrl(examId), {
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        headers:{
          'Content-Type':'application/json',
          'Accept':'application/json',
          'X-Requested-With':'XMLHttpRequest',
          'X-CSRF-TOKEN':csrf
        },
        body:JSON.stringify({ nisn, answers })
      });
      const j = await readJsonResponse(r);
      if (!r.ok || !j?.success) throw new Error(j?.message || `Gagal menyimpan nilai (HTTP ${r.status}).`);

      setStatus(`Nilai tersimpan: ${j.data?.name || lastResult.student?.name || 'Siswa'} (${j.data?.nisn || nisn}) · nilai ${j.data?.score ?? lastResult.grade?.score ?? '-'} · benar ${j.data?.correct_count ?? '-'}/${j.data?.total_questions ?? '-'}.`, 'ok');
      saveBtn.textContent = '✓ Nilai Tersimpan';
      saveBtn.disabled = true;
      saveBtn.dataset.saved = '1';
    } catch (e) {
      console.error('[eduXid LJK] Simpan Nilai gagal', e);
      setStatus('Simpan Nilai gagal: ' + (e?.message || String(e)), 'err');
      saveBtn.textContent = previousText || 'Simpan Nilai';
      saveBtn.disabled = false;
    }
  });

  // Pastikan seluruh tombol benar-benar bereaksi walau Alpine/Blade selesai lebih lambat.
  enableAfterExamChosen(false);
  thresholdValue.textContent = threshold.value;
  console.info('[eduXid LJK Laptop v13R11] aktif', { template:CORE.template, scanner:CORE.version });
})();
