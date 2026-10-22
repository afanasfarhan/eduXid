(() => {
  'use strict';

  const root = document.getElementById('ljk-laptop-v13r9');
  if (!root) return;
  const CORE = window.EDUXID_LJK_SCANNER_V13R9;
  if (!CORE) {
    console.error('[eduXid LJK Laptop v13R9] Scanner core tidak tersedia.');
    const sb = root.querySelector('#ljk-status');
    if (sb) { sb.textContent = 'Mesin Pemindai LJK v13R9 gagal dimuat. Tekan Ctrl+F5 lalu coba lagi.'; sb.className='rounded-lg border p-3 text-sm bg-red-50 border-red-200 text-red-800'; }
    return;
  }

  const $ = s => root.querySelector(s);
  const examSelect = $('#ljk-exam'), fileInput = $('#ljk-file');
  const cameraBtn = $('#ljk-camera'), galleryBtn = $('#ljk-gallery'), processBtn = $('#ljk-process');
  const syncBtn = $('#ljk-sync-private'), saveBtn = $('#ljk-save');
  const preview = $('#ljk-preview'), canvas = $('#ljk-canvas'), statusBox = $('#ljk-status');
  const packageInfo = $('#ljk-package-info'), threshold = $('#ljk-threshold');
  const thresholdWrap = $('#ljk-threshold-wrap'), thresholdValue = $('#ljk-threshold-value');
  const effectiveThreshold = $('#ljk-effective-threshold');
  const resultBox = $('#ljk-result'), summary = $('#ljk-summary'), body = $('#ljk-result-body'), warning = $('#ljk-review-warning');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  let pkg = null, file = null, objectUrl = null, lastResult = null;

  function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function urlFrom(t, k, v) { return String(t || '').replace(k, encodeURIComponent(String(v))); }
  const packageUrl = id => urlFrom(root.dataset.packageUrlTemplate, '__EXAM__', id);
  const commitUrl = id => urlFrom(root.dataset.commitUrlTemplate, '__EXAM__', id);
  const syncUrl = id => urlFrom(root.dataset.syncUrlTemplate, '__EXAM__', id);

  function setStatus(text, type='') {
    statusBox.textContent = text;
    statusBox.className = 'rounded-lg border p-3 text-sm ' + (type === 'ok'
      ? 'bg-green-50 border-green-200 text-green-800' : type === 'err'
      ? 'bg-red-50 border-red-200 text-red-800' : type === 'warn'
      ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-gray-50 border-gray-200 text-gray-700');
  }

  function resolveStudent(nisn) {
    if (!/^\d{10}$/.test(String(nisn || ''))) return null;
    return (pkg?.students || []).find(s => String(s.nisn || '') === String(nisn)) || null;
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

  async function loadPackage() {
    const id = examSelect.value;
    pkg = null; lastResult = null; resultBox.classList.add('hidden');
    cameraBtn.disabled = galleryBtn.disabled = processBtn.disabled = syncBtn.disabled = !id;
    if (!id) { packageInfo.classList.add('hidden'); setStatus('Pilih Paket Ujian terlebih dahulu.'); return; }
    setStatus('Memuat kunci privat, jenis soal, dan roster siswa...');
    try {
      const r = await fetch(packageUrl(id), { headers:{Accept:'application/json'} });
      const j = await r.json();
      if (!r.ok || !j.success) throw new Error(j.message || 'Gagal memuat paket privat.');
      pkg = j.data;
      packageInfo.innerHTML = `<b>${escapeHtml(pkg.title)}</b> · ${pkg.question_count} soal · ${pkg.students?.length || 0} siswa · kunci privat tersedia · Template ${escapeHtml(pkg.template_id || 'LJK-A4-V3')}.`;
      packageInfo.classList.remove('hidden');
      cameraBtn.disabled = galleryBtn.disabled = syncBtn.disabled = false;
      processBtn.disabled = !file;
      setStatus('Paket siap, kunci jawaban privat tersedia. Ambil/pilih foto LJK-A4-V3 lalu tekan Baca LJK.', 'ok');
    } catch (e) { setStatus('Gagal memuat paket: ' + e.message, 'err'); }
  }

  examSelect.addEventListener('change', loadPackage);
  cameraBtn.addEventListener('click', () => { fileInput.setAttribute('capture','environment'); fileInput.click(); });
  galleryBtn.addEventListener('click', () => { fileInput.removeAttribute('capture'); fileInput.click(); });
  fileInput.addEventListener('change', () => {
    file = fileInput.files?.[0] || null;
    if (!file) return;
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = URL.createObjectURL(file); preview.src = objectUrl; preview.classList.remove('hidden');
    processBtn.disabled = !pkg; setStatus('Foto siap. Tekan Baca LJK.', 'ok');
  });
  threshold.addEventListener('input', () => thresholdValue.textContent = threshold.value);
  threshold.addEventListener('change', () => { if (file && pkg) process(); });

  syncBtn.addEventListener('click', async () => {
    if (!pkg) return;
    syncBtn.disabled = true; setStatus('Mengirim paket privat ke PWA Guru...');
    try {
      const r = await fetch(syncUrl(pkg.exam_id), { method:'POST', headers:{'X-CSRF-TOKEN':csrf,Accept:'application/json'} });
      const j = await r.json(); if (!r.ok || !j.success) throw new Error(j.message || 'Sinkronisasi paket privat gagal.');
      setStatus(j.message || 'Paket privat dikirim ke PWA Guru.', 'ok');
    } catch (e) { setStatus(e.message, 'err'); }
    finally { syncBtn.disabled = false; }
  });

  function copyCanvas(src, dst) {
    dst.width = src.width; dst.height = src.height;
    dst.getContext('2d').drawImage(src, 0, 0); dst.classList.remove('hidden');
  }

  async function process() {
    if (!file || !pkg) return;
    processBtn.disabled = true; saveBtn.disabled = true; resultBox.classList.add('hidden');
    try {
      const scan = await CORE.scanFile(file, {
        minThreshold: Number(threshold.value || 38),
        questionCount: pkg.question_count,
        maxSide: 2400,
        previewScale: 0.5,
        onStage: (_stage, text) => setStatus(text)
      });
      if (scan.previewCanvas) copyCanvas(scan.previewCanvas, canvas);
      effectiveThreshold.textContent = String(Math.round(scan.threshold * 10) / 10);
      thresholdWrap.classList.remove('hidden');
      const student = resolveStudent(scan.nisn.nisn);
      const grade = gradeRows(scan.answers);
      lastResult = { ...scan, student, grade };
      renderResult();

      const problems = [];
      if (!student) problems.push(`NISN ${scan.nisn.nisn} belum cocok dengan roster.`);
      if (scan.nisn.ambiguousCount) problems.push(`${scan.nisn.ambiguousCount} digit NISN perlu review.`);
      if (grade.review) problems.push(`${grade.review} jawaban berada di zona abu-abu dan perlu review.`);
      if (grade.invalid) problems.push(`${grade.invalid} pilihan ganda biasa memiliki lebih dari satu isian.`);
      if (problems.length) setStatus('Pemindaian selesai, tetapi perlu review: ' + problems.join(' '), 'warn');
      else setStatus(`Pemindaian presisi selesai: ${student.name} (${scan.nisn.nisn}), nilai sementara ${grade.score}. Jawaban kosong dipertahankan sebagai kosong.`, 'ok');
    } catch (e) { console.error(e); setStatus('Proses koreksi gagal: ' + e.message, 'err'); }
    finally { processBtn.disabled = false; }
  }

  function statusCell(r) {
    if (r.ambiguous) return '<span class="text-amber-700 font-semibold">Perlu review</span>';
    if (r.invalid) return '<span class="text-amber-700 font-semibold">Ganda tidak valid</span>';
    if (r.blank) return '<span class="text-slate-500 font-semibold">Kosong</span>';
    return r.ok ? '<span class="text-green-700 font-semibold">Benar</span>' : '<span class="text-red-700 font-semibold">Salah</span>';
  }

  function renderResult() {
    if (!lastResult) return;
    const g = lastResult.grade, s = lastResult.student;
    summary.innerHTML = `
      <div class="bg-white border rounded-lg p-3"><small class="text-gray-500">NISN</small><input id="ljk-nisn-edit" class="block mt-1 w-full rounded border-gray-300 text-sm font-bold" maxlength="10" value="${escapeHtml(lastResult.nisn.nisn.replace(/\?/g,''))}" placeholder="10 digit NISN"></div>
      <div class="bg-white border rounded-lg p-3"><small class="text-gray-500">Nama Siswa</small><b class="block text-base text-gray-800" id="ljk-student-name">${escapeHtml(s?.name || 'Tidak ditemukan')}</b></div>
      <div class="bg-white border rounded-lg p-3"><small class="text-gray-500">Nilai</small><b class="block text-lg">${g.score}</b></div>
      <div class="bg-white border rounded-lg p-3"><small class="text-gray-500">Benar</small><b class="block text-lg">${g.correct}/${g.graded}</b></div>
      <div class="bg-white border rounded-lg p-3"><small class="text-gray-500">Marker</small><b class="block text-lg">${Math.round(lastResult.markerConfidence*100)}%</b></div>`;

    body.innerHTML = g.rows.map((r, i) => `<tr data-row="${i}" class="${r.ambiguous ? 'bg-amber-50' : ''}">
      <td class="p-2 border text-center">${i+1}</td>
      <td class="p-2 border text-center">${escapeHtml(r.type)}</td>
      <td class="p-2 border"><input class="ljk-answer-edit w-28 rounded border-gray-300 text-sm" data-i="${i}" value="${escapeHtml(r.answer)}" placeholder="Kosong / A,B"><div class="text-[10px] text-gray-400 mt-1">${r.near.length ? 'Hampir terisi: '+escapeHtml(r.near.join(',')) : ''}</div></td>
      <td class="p-2 border text-center">${escapeHtml(r.key)}</td>
      <td class="p-2 border text-center">${statusCell(r)}</td>
    </tr>`).join('');

    summary.querySelector('#ljk-nisn-edit')?.addEventListener('change', e => {
      const nisn = String(e.target.value || '').replace(/\D/g,'').slice(0,10);
      e.target.value = nisn;
      lastResult.nisn.nisn = nisn.length === 10 ? nisn : nisn.padEnd(10, '?');
      lastResult.nisn.ambiguousCount = nisn.length === 10 ? 0 : 10 - nisn.length;
      lastResult.student = resolveStudent(nisn);
      renderResult();
    });

    body.querySelectorAll('.ljk-answer-edit').forEach(input => input.addEventListener('change', () => {
      const i = Number(input.dataset.i), set = CORE.normalizeSet(input.value);
      lastResult.answers[i].selected = set; lastResult.answers[i].value = set.join(',');
      lastResult.answers[i].near = []; lastResult.answers[i].ambiguous = false; lastResult.answers[i].blank = set.length === 0; lastResult.answers[i].manual = true;
      lastResult.grade = gradeRows(lastResult.answers); renderResult();
    }));

    const problems = [];
    if (!lastResult.student) problems.push('Nama siswa belum ditemukan.');
    if (lastResult.nisn.ambiguousCount || lastResult.nisn.nisn.includes('?')) problems.push('NISN belum pasti.');
    if (g.review) problems.push(`${g.review} jawaban perlu review.`);
    if (g.invalid) problems.push(`${g.invalid} soal pilihan ganda biasa memiliki isian ganda.`);
    warning.textContent = problems.join(' ');
    saveBtn.disabled = problems.length > 0;
    resultBox.classList.remove('hidden');
  }

  processBtn.addEventListener('click', (e) => { e.preventDefault(); process().catch(err => { console.error(err); setStatus('Baca LJK gagal: '+(err?.message||err), 'err'); }); });
  saveBtn.addEventListener('click', async () => {
    if (!lastResult || !pkg || saveBtn.disabled) return;
    saveBtn.disabled = true; setStatus('Menyimpan nilai melalui koreksi CBT resmi...');
    try {
      const answers = lastResult.answers.map(a => CORE.normalizeSet(a.selected));
      const r = await fetch(commitUrl(pkg.exam_id), {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
        body:JSON.stringify({ nisn:lastResult.nisn.nisn, answers })
      });
      const j = await r.json(); if (!r.ok || !j.success) throw new Error(j.message || 'Gagal menyimpan nilai.');
      setStatus(`Nilai tersimpan: ${j.data.name} (${j.data.nisn}) · nilai ${j.data.score} · benar ${j.data.correct_count}/${j.data.total_questions}.`, 'ok');
      saveBtn.disabled = true;
    } catch (e) { setStatus(e.message, 'err'); saveBtn.disabled = false; }
  });

  console.info('[eduXid LJK Laptop v13R9] aktif', { template:CORE.template, scanner:CORE.version });
})();
