<x-app-layout>
<x-slot name="header">
<div>
  <h2 class="font-semibold text-xl text-gray-800 leading-tight">Koreksi LJK</h2>
  <p class="mt-1 text-sm text-gray-500">Koreksi otomatis dari kamera, gambar, PDF, atau ZIP dengan review manual bila diperlukan.</p>
</div>
</x-slot>

<div class="py-12" id="ljk-auto-v14-root"
 data-package-url="{{ url('/guru/ljk-auto/package') }}"
 data-manual-key-base="{{ url('/guru/ljk-auto/manual-key') }}"
 data-standalone-base="{{ url('/guru/ljk-auto/standalone') }}"
 data-results-url="{{ route('guru.ljk-auto.results') }}"
 data-store-url="{{ route('guru.ljk-auto.store') }}"
 data-result-base="{{ url('/guru/ljk-auto/results') }}"
 data-clear-base="{{ url('/guru/ljk-auto/results/all') }}"
 data-publish-base="{{ url('/guru/ljk-auto/publish') }}"
 data-sync-pwa-base="{{ url('/guru/ljk-auto/sync-pwa') }}"
 data-zip-url="{{ route('guru.ljk-auto.import-zip') }}"
 data-pdf-url="{{ route('guru.ljk-auto.import-pdf') }}"
 data-csrf="{{ csrf_token() }}"
 data-initial-exam="{{ $initialExamId ?? '' }}">

<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
<section class="eduxid-page-card eduxid-page-card-emerald p-5 sm:p-6">
  <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
    <div class="max-w-2xl">
      <div class="text-lg font-bold text-gray-800">Pemindai LJK</div>
      <p class="mt-1 text-sm leading-6 text-gray-500">Pilih Paket Ujian atau buat kunci manual tanpa Paket Ujian, pindai LJK, lalu kirim hasil ke Buku Nilai.</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a href="{{ route('guru.cbt') }}" class="eduxid-btn rounded-md border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 hover:bg-gray-50">← Manajemen Ujian</a>
      <a href="{{ route('guru.cbt.koreksi') }}" class="eduxid-btn rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-700 hover:bg-red-100">Koreksi CBT</a>
      @if(!empty($templateTool['url']))
        <a href="{{ $templateTool['url'] }}" target="_blank" rel="noopener" class="eduxid-btn rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-bold text-indigo-700 hover:bg-indigo-100">Template LJK</a>
      @else
        <button type="button" disabled class="eduxid-btn cursor-not-allowed rounded-md border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-400">Template LJK</button>
      @endif
      <a href="{{ route('guru.ljk-auto.roster-print') }}" target="_blank" rel="noopener" class="eduxid-btn rounded-md border border-blue-200 bg-blue-50 px-4 py-2.5 text-sm font-bold text-blue-700 hover:bg-blue-100">🖨️ LJK Per Rombel (Biodata Otomatis)</a>
      <button id="ljk-calibration-open" type="button" class="eduxid-btn rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-bold text-amber-800 hover:bg-amber-100">Kalibrasi</button>
      <button id="ljk-scroll-results" type="button" class="eduxid-btn rounded-md bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Hasil & Review</button>
    </div>
  </div>
</section>

<section class="eduxid-page-card eduxid-page-card-indigo overflow-hidden">
  <div class="grid lg:grid-cols-[minmax(0,1fr)_320px]">
    <div class="border-b border-slate-100 p-5 sm:p-6 lg:border-b-0 lg:border-r">
      <div class="flex items-center gap-3">
        <span class="grid h-9 w-9 place-items-center rounded-xl bg-indigo-600 text-sm font-black text-white">1</span>
        <div><h3 class="font-black text-slate-900">Pilih Sumber Koreksi</h3><p class="mt-0.5 text-xs text-slate-500">Koreksi dengan Paket Ujian atau Kunci Manual Guru tanpa membuat Paket Ujian.</p></div>
      </div>
      <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Sumber koreksi LJK">
        <button type="button" id="ljk-mode-package" class="eduxid-btn rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white">Paket Ujian</button>
        <button type="button" id="ljk-mode-standalone" class="eduxid-btn rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-bold text-blue-700">Kunci Manual Tanpa Paket</button>
      </div>
      <style>
/* Khusus halaman Koreksi LJK: tiap kunci berada satu baris di samping jenis soal.
   Lima kelompok @10 soal tetap ada; jumlah kolom layar menyesuaikan ruang tersedia. */
.ljk-key-columns{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,290px),1fr));gap:12px;align-items:start}
.ljk-key-column{min-width:0;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;background:#fff}
.ljk-key-col-head{margin:0;background:#dbeafe;color:#1e40af;padding:10px;text-align:center;font-size:12px;font-weight:800}
.ljk-key-col-rows{padding:7px}
.ljk-key-row{padding:7px 3px;border-bottom:1px solid #e2e8f0;min-width:0}
.ljk-key-row:last-child{border-bottom:0}
.ljk-key-row-inline{display:grid;grid-template-columns:24px 82px minmax(0,1fr);gap:7px;align-items:center;min-width:0}
.ljk-key-num{font-weight:800;color:#334155;font-size:12px;white-space:nowrap}
.ljk-key-type,.ljk-key-type-static{display:flex;align-items:center;width:82px;min-width:0;min-height:31px;font-size:11px;font-weight:650;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155}
.ljk-key-type{padding:4px 16px 4px 4px}
.ljk-key-type-static{padding:4px 5px;background:#f8fafc}
.ljk-key-choices{display:flex;flex-wrap:nowrap;justify-content:space-between;align-items:center;gap:5px;min-width:0}
.ljk-key-choices label{display:inline-flex;align-items:center;justify-content:center;gap:3px;min-width:25px;min-height:28px;padding:3px 2px;font-size:11px;font-weight:750;color:#1e40af;background:#eff6ff;border:1px solid #dbeafe;border-radius:5px;cursor:pointer;white-space:nowrap}
.ljk-key-choices label:has(input:checked){background:#dbeafe;border-color:#93c5fd;color:#1e3a8a}
.ljk-key-choices input{width:12px;height:12px;margin:0;flex-shrink:0;accent-color:#2563eb}
.ljk-key-choices label.ljk-key-disabled{opacity:.32;cursor:not-allowed}
.ljk-key-choices label:focus-within{outline:2px solid #2563eb;outline-offset:1px}
@media(max-width:350px){.ljk-key-row-inline{grid-template-columns:20px 62px minmax(0,1fr);gap:4px}.ljk-key-type,.ljk-key-type-static{width:62px;font-size:10px}.ljk-key-choices{gap:2px}.ljk-key-choices label{min-width:22px;font-size:10px}}

</style>
      <div id="ljk-standalone-panel" class="mt-4 hidden rounded-xl border border-blue-200 bg-blue-50 p-4">
        <h4 class="font-extrabold text-slate-900">Buat Sesi Koreksi Kunci Manual</h4>
        <p class="mt-1 text-xs leading-5 text-slate-600">Tidak membuat Paket Ujian maupun Bank Soal. Sesi diikat ke guru, mata pelajaran, rombel, dan tanggal penilaian.</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="text-xs font-semibold text-slate-700">Penugasan Mengajar
            <select id="ljk-standalone-assignment" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Memuat penugasan...</option></select>
          </label>
          <label class="text-xs font-semibold text-slate-700">Judul Koreksi
            <input id="ljk-standalone-title" type="text" maxlength="150" placeholder="Contoh: Ulangan Vektor" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
          </label>
          <label class="text-xs font-semibold text-slate-700">Tanggal Penilaian
            <input id="ljk-standalone-date" type="date" value="{{ now()->format('Y-m-d') }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
          </label>
          <label class="text-xs font-semibold text-slate-700">Jenis Penilaian
            <select id="ljk-standalone-type" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="sumatif">Sumatif</option><option value="formatif">Formatif</option><option value="diagnostik">Diagnostik</option></select>
          </label>
          <label class="text-xs font-semibold text-slate-700">Jumlah Soal (1–50)
            <input id="ljk-standalone-count" type="number" min="1" max="50" value="50" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
          </label>
        </div>
        <div id="ljk-standalone-grid" class="ljk-key-columns mt-4"></div>
        <button id="ljk-standalone-create" type="button" class="eduxid-btn mt-4 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700">Simpan Sesi dan Aktifkan</button>
        <div class="mt-5 border-t border-blue-200 pt-4">
          <label class="text-sm font-bold text-slate-700" for="ljk-standalone-sessions">Buka Sesi Kunci Manual Tersimpan</label>
          <div class="mt-2 flex flex-wrap items-center gap-2">
            <select id="ljk-standalone-sessions" class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm"><option value="">Pilih sesi tersimpan...</option></select>
            <button id="ljk-standalone-delete" type="button" disabled class="eduxid-btn rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-bold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40">Hapus Sesi &amp; Kunci</button>
          </div>
          <p class="mt-1 text-[11px] leading-5 text-slate-600">Penghapusan mengarsipkan hasil koreksi dan mencabut kunci dari PWA Guru. Nilai yang sudah masuk Buku Nilai tetap tersimpan.</p>
        </div>
        <p id="ljk-standalone-status" class="mt-3 text-xs font-medium text-slate-600" aria-live="polite">Pilih penugasan dan lengkapi kunci untuk membuat sesi.</p>
      </div>
      <div id="ljk-package-mode">
      <select id="ljk-exam" class="mt-4 w-full rounded-xl border-slate-300 py-3 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">Pilih Paket Ujian...</option>
        @foreach($exams as $exam)
          <option value="{{ $exam->id }}" @disabled(!($exam->ljk_compatible ?? false))>
            {{ $exam->judul_ujian ?? ('Paket #' . $exam->id) }} · {{ $exam->questions_count ?? 0 }} soal{{ ($exam->ljk_compatible ?? false) ? '' : ' · Tidak kompatibel LJK' }}
          </option>
        @endforeach
      </select>
      <div id="ljk-package-meta" class="mt-2 text-xs leading-5 text-slate-500">Pilih paket terlebih dahulu untuk memuat peserta dan kunci koreksi. LJK hanya mendukung Pilihan Ganda, Pilihan Majemuk, dan Benar/Salah.</div>
      </div>
    </div>
    <div class="bg-slate-50 p-5 sm:p-6">
      <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Aksi Paket</p>
      <div class="mt-3 grid gap-2">
        <button id="ljk-sync-pwa" type="button" disabled class="eduxid-btn w-full rounded-md border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-bold text-indigo-700 hover:bg-indigo-100 disabled:opacity-40">Kirim ke PWA Guru</button>
      </div>
      <p class="mt-3 text-[11px] leading-5 text-slate-500">Pengiriman nilai dilakukan setelah hasil koreksi diperiksa pada tabel Hasil &amp; Review di bawah.</p>
    </div>
  </div>
  <div class="border-t border-indigo-100 bg-indigo-50/40 p-5 sm:p-6" id="ljk-manual-key-section">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h3 class="text-sm font-extrabold text-slate-900">Kunci Jawaban Koreksi LJK</h3>
        <p class="mt-1 text-xs leading-5 text-slate-600">Pilih sumber kunci. Kunci manual tersimpan hanya pada laptop guru dan dapat dikirim secara privat ke PWA Guru. Kunci asli Bank Soal tidak diubah.</p>
      </div>
      <select id="ljk-key-source" class="rounded-lg border-slate-300 py-2.5 text-sm font-bold focus:border-blue-500 focus:ring-blue-500" disabled>
        <option value="package">Kunci Paket Ujian</option>
        <option value="manual">Kunci Manual Guru</option>
      </select>
    </div>
    <div id="ljk-manual-key-editor" class="mt-4 hidden">
      <p class="mb-3 text-xs leading-5 text-slate-600">Isi seluruh nomor. Pilihan ganda: satu A–E; pilihan majemuk: centang semua jawaban benar; benar/salah: A = Benar, B = Salah.</p>
      <div id="ljk-manual-key-grid" class="ljk-key-columns"></div>
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <button id="ljk-manual-key-save" type="button" class="eduxid-btn rounded-md bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700">Simpan &amp; Gunakan Kunci Manual</button>
        <span id="ljk-manual-key-status" class="text-xs font-semibold text-slate-600" aria-live="polite">Pilih Paket Ujian.</span>
      </div>
    </div>
    <button id="ljk-manual-key-delete" type="button" disabled class="eduxid-btn mt-3 hidden rounded-md border border-red-200 bg-white px-4 py-2.5 text-sm font-bold text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-40">Hapus Kunci Manual</button>
    <p id="ljk-key-source-status" class="mt-3 text-xs leading-5 text-slate-500">Pilih Paket Ujian sebelum menentukan sumber kunci.</p>
  </div>
  <div class="border-t border-slate-100 p-5 sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <span class="grid h-9 w-9 place-items-center rounded-xl bg-emerald-600 text-sm font-black text-white">2</span>
        <div><h3 class="font-black text-slate-900">Masukkan LJK</h3><p class="mt-0.5 text-sm text-slate-500">Kamera, gambar, PDF, dan ZIP diproses oleh mesin koreksi yang sama.</p></div>
      </div>
      <div id="ljk-engine-state" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-600">Pemindai dimuat saat pemindaian pertama</div>
    </div>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      <label class="eduxid-upload-card eduxid-upload-camera group cursor-pointer rounded-xl border border-indigo-200 bg-indigo-50 p-4"><div class="eduxid-upload-icon">📷</div><div class="mt-2 font-black text-slate-900">Kamera / Webcam</div><div class="mt-1 text-xs leading-5 text-slate-500">Satu lembar, langsung dikoreksi.</div><input id="ljk-camera" type="file" accept="image/*" capture="environment" class="hidden"></label>
      <label class="eduxid-upload-card eduxid-upload-image group cursor-pointer rounded-xl border border-sky-200 bg-sky-50 p-4"><div class="eduxid-upload-icon">🖼️</div><div class="mt-2 font-black text-slate-900">JPG / PNG</div><div class="mt-1 text-xs leading-5 text-slate-500">Pilih banyak gambar sekaligus.</div><input id="ljk-images" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple class="hidden"></label>
      <label class="eduxid-upload-card eduxid-upload-pdf group cursor-pointer rounded-xl border border-violet-200 bg-violet-50 p-4"><div class="eduxid-upload-icon">📄</div><div class="mt-2 font-black text-slate-900">PDF Pemindai</div><div class="mt-1 text-xs leading-5 text-slate-500">Satu halaman PDF dibaca sebagai satu LJK.</div><input id="ljk-pdf" type="file" accept="application/pdf,.pdf" class="hidden"></label>
      <label class="eduxid-upload-card eduxid-upload-zip group cursor-pointer rounded-xl border border-amber-200 bg-amber-50 p-4"><div class="eduxid-upload-icon">🗜️</div><div class="mt-2 font-black text-slate-900">ZIP Gambar</div><div class="mt-1 text-xs leading-5 text-slate-500">JPG/JPEG/PNG diproses massal berurutan.</div><input id="ljk-zip" type="file" accept=".zip,application/zip" class="hidden"></label>
    </div>
  </div>
</section>

<section id="ljk-live" class="hidden eduxid-page-card eduxid-page-card-emerald bg-emerald-50 p-5"><div class="text-xs font-bold uppercase tracking-wider text-emerald-700">Hasil terakhir</div><div id="ljk-live-body" class="mt-2"></div></section>

<section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <div class="eduxid-stat-card eduxid-stat-neutral eduxid-page-card eduxid-page-card-slate p-4"><div class="text-xs font-semibold uppercase text-slate-400">Diproses</div><div id="sum-total" class="mt-2 text-3xl font-black">0</div></div>
  <div class="eduxid-stat-card eduxid-stat-emerald eduxid-page-card eduxid-page-card-emerald bg-emerald-50 p-4"><div class="text-xs font-semibold uppercase text-emerald-600">Berhasil</div><div id="sum-success" class="mt-2 text-3xl font-black text-emerald-700">0</div></div>
  <div class="eduxid-stat-card eduxid-stat-amber eduxid-page-card eduxid-page-card-amber bg-amber-50 p-4"><div class="text-xs font-semibold uppercase text-amber-600">Perlu Dicek</div><div id="sum-review" class="mt-2 text-3xl font-black text-amber-700">0</div></div>
  <div class="eduxid-stat-card eduxid-stat-rose eduxid-page-card eduxid-page-card-red bg-rose-50 p-4"><div class="text-xs font-semibold uppercase text-rose-600">Duplikat / Gagal</div><div id="sum-problem" class="mt-2 text-3xl font-black text-rose-700">0</div></div>
</section>

<section id="ljk-results-section" class="eduxid-page-card eduxid-page-card-slate overflow-hidden">
  <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-start gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-900 text-sm font-black text-white">3</span><div><h3 class="font-black text-slate-900">Hasil & Review</h3><p id="ljk-progress" class="mt-1 text-xs text-slate-500">Pilih Paket Ujian atau sesi kunci manual untuk mulai.</p></div></div>
    <div class="flex flex-wrap justify-end gap-2">
      <button id="ljk-only-review" type="button" class="eduxid-btn rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">⚠ Lihat yang Perlu Dicek</button>
    </div>
  </div>
  <div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3 text-left">No.</th><th class="px-4 py-3 text-left">Nama Murid</th><th class="px-4 py-3 text-left">Kelas</th><th class="px-4 py-3 text-left">NISN</th><th class="px-4 py-3 text-left">Skor Betul</th><th class="px-4 py-3 text-left">Nilai</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr></thead><tbody id="ljk-results-body" class="divide-y divide-slate-100"></tbody></table></div>
  <div class="border-t border-slate-100 bg-slate-50/80 px-5 py-4 sm:px-6">
    <div class="flex flex-wrap items-center justify-end gap-2">
      <button id="ljk-item-analysis" type="button" disabled data-analysis-base="{{ url('/guru/ujian/__EXAM__/analisis-butir') }}" data-analysis-manual="{{ url('/guru/ljk-auto/standalone/__SESSION__/analisis-butir') }}" class="eduxid-btn rounded-md border border-violet-200 bg-violet-50 px-3 py-2.5 text-sm font-bold text-violet-700 hover:bg-violet-100 disabled:opacity-40">Analisis Butir</button>
      <button id="ljk-download-results" type="button" disabled class="eduxid-btn rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100 disabled:opacity-40">⬇ Download Hasil Excel</button>
      <button id="ljk-clear-all" type="button" disabled class="eduxid-btn rounded-md border border-rose-200 bg-white px-4 py-2.5 text-sm font-bold text-rose-700 hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40">🗑 Hapus Semua Hasil Koreksi</button>
      <button id="ljk-publish" type="button" disabled class="eduxid-btn rounded-md bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-40">✓ Kirim Hasil ke Buku Nilai</button>
    </div>
    <p class="mt-2 text-xs leading-5 text-slate-500 sm:text-right">Hapus Semua mengarsipkan hasil sesi yang dipilih; nilai yang sudah tersimpan di Buku Nilai tidak dihapus.</p>
  </div>
</section>



</div>


<div id="ljk-calibration-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-sm sm:p-6">
  <div class="mx-auto my-5 max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
      <div>
        <div class="text-xs font-bold uppercase tracking-wider text-amber-600">Kalibrasi Pemindai</div>
        <h3 class="mt-1 text-lg font-bold text-slate-900">Kalibrasi LJK-A4-V3</h3>
        <p class="mt-1 text-xs text-slate-500">Setelan disimpan lokal di laptop ini dan digunakan pada pemindaian berikutnya.</p>
      </div>
      <button type="button" id="ljk-calibration-close" class="h-10 w-10 rounded-xl border border-slate-200 text-xl">&times;</button>
    </div>

    <div class="space-y-5 p-5 sm:p-6">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="block text-sm font-bold text-slate-800" for="ljk-cal-marker">Kepekaan marker</label>
          <input id="ljk-cal-marker" type="number" min="0.10" max="0.95" step="0.01"
                 class="mt-2 w-full rounded-xl border-slate-300 py-3">
          <p class="mt-1 text-xs text-slate-400">Semakin tinggi, marker harus semakin tegas.</p>
        </div>
        <div>
          <label class="block text-sm font-bold text-slate-800" for="ljk-cal-bubble">Kepekaan isian</label>
          <input id="ljk-cal-bubble" type="number" min="0.10" max="0.95" step="0.01"
                 class="mt-2 w-full rounded-xl border-slate-300 py-3">
          <p class="mt-1 text-xs text-slate-400">Mengatur batas gelap untuk pilihan jawaban.</p>
        </div>
        <div>
          <label class="block text-sm font-bold text-slate-800" for="ljk-cal-ambiguity">Batas jawaban ambigu</label>
          <input id="ljk-cal-ambiguity" type="number" min="0.01" max="0.50" step="0.01"
                 class="mt-2 w-full rounded-xl border-slate-300 py-3">
          <p class="mt-1 text-xs text-slate-400">Selisih kecil antar pilihan akan ditandai Perlu Dicek.</p>
        </div>
        <div>
          <label class="block text-sm font-bold text-slate-800" for="ljk-cal-confidence">Batas keyakinan minimum</label>
          <input id="ljk-cal-confidence" type="number" min="0.10" max="1.00" step="0.01"
                 class="mt-2 w-full rounded-xl border-slate-300 py-3">
          <p class="mt-1 text-xs text-slate-400">Hasil di bawah batas ini ditandai untuk diperiksa.</p>
        </div>
      </div>

      <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <input id="ljk-cal-perspective" type="checkbox" class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
        <span>
          <span class="block text-sm font-bold text-slate-800">Koreksi perspektif otomatis</span>
          <span class="mt-1 block text-xs text-slate-500">Gunakan empat marker untuk merapikan kemiringan dan perspektif foto sebelum membaca LJK.</span>
        </span>
      </label>

      <div class="rounded-xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800">
        Kalibrasi ini diterapkan ke Pemindai melalui opsi pemindaian dan variabel kompatibilitas global. Jika mesin Pemindai versi lama mengabaikan salah satu parameter, parameter lain tetap aman digunakan.
      </div>
    </div>

    <div class="flex flex-wrap justify-between gap-2 border-t border-slate-100 bg-slate-50 px-5 py-4">
      <button id="ljk-calibration-reset" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Reset Default</button>
      <div class="flex gap-2">
        <button id="ljk-calibration-cancel" type="button" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700">Batal</button>
        <button id="ljk-calibration-save" type="button" class="rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-bold text-white hover:bg-amber-600">Simpan Kalibrasi</button>
      </div>
    </div>
  </div>
</div>

<div id="ljk-review-modal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-slate-950/60 p-3 backdrop-blur-sm sm:p-6">
 <div class="mx-auto my-5 max-w-5xl overflow-hidden rounded-2xl bg-white shadow-2xl">
  <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><div class="text-xs font-bold uppercase tracking-wider text-indigo-600">Review Manual</div><h3 class="mt-1 text-lg font-bold text-slate-900">Periksa / Edit Hasil LJK</h3></div><button type="button" id="ljk-review-close" class="h-10 w-10 rounded-xl border border-slate-200 text-xl">&times;</button></div>
  <div class="grid lg:grid-cols-[1.15fr_.85fr]">
   <div class="bg-slate-100 p-4 sm:p-6"><div id="ljk-review-image-wrap" class="hidden"><img id="ljk-review-image" class="mx-auto max-h-[66vh] rounded-xl border bg-white shadow-sm" alt="Foto LJK"></div><div id="ljk-review-image-empty" class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">Foto sumber tidak tersedia.</div></div>
   <div class="p-5 sm:p-6">
    <label class="block text-sm font-bold text-slate-800">NISN</label>
    <input id="ljk-review-nisn" class="mt-2 w-full rounded-xl border-slate-300 py-3 font-mono">

    <div class="mt-5 flex flex-wrap items-end justify-between gap-2">
      <div>
        <label class="block text-sm font-bold text-slate-800">Jawaban hasil Pemindai</label>
        <p class="mt-1 text-xs leading-5 text-slate-500">
          Klik opsi untuk memperbaiki hasil scan. Pilihan Ganda memakai A–E, Benar/Salah memakai A=Benar dan B=Salah,
          sedangkan Pilihan Majemuk dapat memilih lebih dari satu opsi. Gunakan <strong>Kosong</strong> bila tidak ada jawaban.
        </p>
      </div>
      <div id="ljk-review-answer-summary" class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600"></div>
    </div>

    <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50">
      <div class="grid grid-cols-[52px_minmax(0,1fr)] border-b border-slate-200 bg-white px-3 py-2 text-[11px] font-bold uppercase tracking-wide text-slate-400">
        <div>No.</div><div>Jawaban</div>
      </div>
      <div id="ljk-review-answer-grid" class="max-h-[48vh] divide-y divide-slate-200 overflow-y-auto"></div>
    </div>

    <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-500">
      <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-indigo-700">● Terpilih</span>
      <span class="rounded-full bg-violet-50 px-2.5 py-1 text-violet-700">Pilihan Majemuk: boleh lebih dari satu</span>
      <span class="rounded-full bg-slate-100 px-2.5 py-1">Kosong: tidak ada pilihan</span>
    </div>

    <div class="mt-6 flex justify-end gap-2">
      <button id="ljk-review-cancel" type="button" class="rounded-xl border px-4 py-2.5 text-sm font-semibold">Batal</button>
      <button id="ljk-review-save" type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white">Simpan Perubahan</button>
    </div>
  </div>
  </div>
 </div>
</div>
</div>
<script src="{{ asset('js/ljk-excel-export-v1.js') }}?v=LJK-EXCEL-20261010"></script>
<script src="{{ asset('js/ljk-auto-v16-manual-key.js') }}?v=EDUXID-LJK-EXCEL-DB-V21R76-20261015"></script>
<!-- Tombol Analisis Butir diatur oleh JS pemindaian V13R3 berdasarkan sesi aktif dan hasil berhasil. -->

</x-app-layout>
