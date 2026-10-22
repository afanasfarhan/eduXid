<x-app-layout>
<x-slot name="header">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-bold text-2xl text-slate-800 leading-tight">Koreksi LJK</h2>
                <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200">LJK-A4-V3</span>
                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200">Pemrosesan Lokal</span>
            </div>
            <p class="mt-1 text-sm text-slate-500">Pilih paket ujian, ambil foto LJK, periksa hasil pembacaan, lalu simpan nilai.</p>
        </div>
        <a href="{{ route('guru.ljk.v3.template') }}?v=13R11R1" target="_blank"
           class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-300">
            <span>🖨️</span><span>Cetak Template LJK-A4-V3</span>
        </a>
    </div>
</x-slot>

<div class="py-7 max-w-7xl mx-auto sm:px-6 lg:px-8" id="ljk-laptop-v13r11"
     data-csrf="{{ csrf_token() }}"
     data-package-url-template="{{ url('/guru/ljk-v3/package/__EXAM__') }}"
     data-student-url-template="{{ url('/guru/ljk-v3/student/__NISN__') }}"
     data-commit-url-template="{{ url('/guru/ljk-v3/package/__EXAM__/commit') }}"
     data-sync-url-template="{{ url('/guru/ljk-v3/package/__EXAM__/sync-private') }}">

    @if(session('success'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('success') }}</div>@endif
    @if(session('warning'))<div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ session('warning') }}</div>@endif
    @if(session('error'))<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>@endif

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 font-bold text-indigo-700">1</span><div><div class="font-semibold text-slate-800">Pilih Paket Ujian</div><div class="text-xs text-slate-500">Kunci + roster disiapkan lokal</div></div></div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-100 font-bold text-blue-700">2</span><div><div class="font-semibold text-slate-800">Ambil Foto</div><div class="text-xs text-slate-500">Webcam laptop atau pilih file</div></div></div>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-100 font-bold text-emerald-700">3</span><div><div class="font-semibold text-slate-800">Review & Simpan</div><div class="text-xs text-slate-500">Periksa NISN dan jawaban</div></div></div>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1.05fr_1.45fr]">
        <div class="space-y-5">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div><h3 class="font-bold text-slate-800">1. Paket Ujian</h3><p class="text-xs text-slate-500 mt-0.5">Pilih paket yang sesuai dengan LJK yang akan dibaca.</p></div>
                        <span id="ljk-package-badge" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Belum dipilih</span>
                    </div>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label for="ljk-exam" class="mb-1.5 block text-sm font-semibold text-slate-700">Paket Ujian</label>
                        <select id="ljk-exam" class="w-full rounded-xl border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">— Pilih Paket Ujian —</option>
                            @foreach($exams as $exam)
                                <option value="{{ $exam->id }}" data-title="{{ $exam->judul_ujian }}" data-count="{{ $exam->questions_count }}">{{ $exam->judul_ujian }} · {{ $exam->questions_count }} soal</option>
                            @endforeach
                        </select>
                    </div>

                    <div id="ljk-package-info" class="hidden rounded-xl border border-indigo-100 bg-indigo-50 p-3.5 text-sm text-indigo-900"></div>

                    <button type="button" id="ljk-sync-private"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-300 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 disabled:shadow-none"
                        disabled>
                        <span>☁️</span><span>Kirim Paket Privat ke PWA Guru</span>
                    </button>
                    <p class="text-xs leading-relaxed text-slate-500">Paket privat hanya ditujukan ke perangkat guru dan berisi kunci jawaban, jenis soal, serta roster siswa. Kunci tidak dikirim ke PWA siswa.</p>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <div><h3 class="font-bold text-slate-800">2. Sumber Foto LJK</h3><p class="text-xs text-slate-500 mt-0.5">Pastikan seluruh kertas dan empat marker terlihat.</p></div>
                        <span id="ljk-photo-badge" class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Belum ada foto</span>
                    </div>
                </div>
                <div class="p-5">
                    <input id="ljk-file" type="file" accept="image/jpeg,image/png,image/webp,image/*" class="hidden">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <button type="button" id="ljk-camera"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-300 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 disabled:shadow-none"
                            disabled><span>📷</span><span>Buka Kamera</span></button>
                        <button type="button" id="ljk-gallery"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-300 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400 disabled:shadow-none"
                            disabled><span>🖼️</span><span>Pilih Foto</span></button>
                    </div>
                    <div id="ljk-selected-file" class="mt-3 hidden rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600"></div>

                    <button type="button" id="ljk-process"
                        class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-300 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 disabled:shadow-none"
                        disabled><span>✓</span><span>Baca LJK</span></button>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-800">Status Pemindai</h3>
                <div id="ljk-status" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-sm text-slate-700">Pilih Paket Ujian terlebih dahulu.</div>

                <details class="mt-4 rounded-xl border border-slate-200 bg-slate-50/70">
                    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-700">Pengaturan sensitivitas</summary>
                    <div id="ljk-threshold-wrap" class="border-t border-slate-200 p-4 grid gap-3 text-sm">
                        <div class="flex items-center justify-between"><span class="text-slate-600">Sensitivitas minimum isian</span><b id="ljk-threshold-value" class="rounded bg-white px-2 py-1 text-indigo-700">38</b></div>
                        <input id="ljk-threshold" type="range" min="28" max="65" value="38" class="w-full">
                        <p class="text-xs leading-relaxed text-slate-500">Ambang efektif menyesuaikan pencahayaan foto. Nilai lebih tinggi mengurangi kemungkinan bubble kosong terbaca terisi. Ambang efektif: <b id="ljk-effective-threshold">—</b>.</p>
                    </div>
                </details>
            </section>

            <details class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <summary class="cursor-pointer px-5 py-4 font-semibold text-slate-700">Lihat template LJK-A4-V3</summary>
                <div class="border-t border-slate-100 p-4">
                    <p class="mb-3 text-xs leading-relaxed text-slate-500">Gunakan template V3. NISN dan jawaban harus berada di dalam empat marker.</p>
                    <iframe src="{{ route('guru.ljk.v3.template') }}?v=13R11R1#toolbar=0&navpanes=0" class="h-[560px] w-full rounded-xl border bg-slate-50"></iframe>
                </div>
            </details>
        </div>

        <div class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div><h3 class="font-bold text-slate-800">Pratinjau Pemindaian</h3><p class="mt-0.5 text-xs text-slate-500">Foto asli dan hasil pelurusan akan tampil di sini.</p></div>
                </div>
                <div class="grid min-h-[420px] grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="flex min-h-[380px] items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
                        <div id="ljk-preview-placeholder" class="text-center text-sm text-slate-400"><div class="text-4xl mb-2">📄</div>Belum ada foto LJK</div>
                        <img id="ljk-preview" class="hidden max-h-[650px] w-full object-contain rounded-lg" alt="Foto LJK">
                    </div>
                    <div class="flex min-h-[380px] items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
                        <div id="ljk-canvas-placeholder" class="text-center text-sm text-slate-400"><div class="text-4xl mb-2">⌗</div>Hasil pelurusan belum tersedia</div>
                        <canvas id="ljk-canvas" class="hidden max-h-[650px] w-full object-contain rounded-lg"></canvas>
                    </div>
                </div>
            </section>

            <section id="ljk-result" class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 bg-slate-50/80 px-5 py-4"><h3 class="font-bold text-slate-800">3. Hasil Koreksi</h3><p class="mt-0.5 text-xs text-slate-500">Review bagian yang meragukan sebelum menyimpan nilai.</p></div>
                <div id="ljk-summary" class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-5"></div>
                <div class="border-t border-slate-100 p-5">
                    <div class="mb-3 rounded-xl border border-amber-100 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-800">Hijau = terisi pasti. Kuning = perlu review. Kosong yang jelas tetap kosong. Pilihan majemuk ditulis dengan koma, misalnya <b>A,B</b>.</div>
                    <div class="max-h-[560px] overflow-auto rounded-xl border border-slate-200">
                        <table class="w-full border-collapse text-sm">
                            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700"><tr><th class="p-2.5 border-b">No</th><th class="p-2.5 border-b">Jenis</th><th class="p-2.5 border-b">Jawaban terbaca</th><th class="p-2.5 border-b">Kunci</th><th class="p-2.5 border-b">Hasil</th></tr></thead>
                            <tbody id="ljk-result-body" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div id="ljk-review-warning" class="text-sm font-medium text-amber-700"></div>
                        <button type="button" id="ljk-save" class="inline-flex items-center justify-center rounded-xl bg-green-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-green-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 disabled:shadow-none" disabled>Simpan Nilai</button>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div id="ljk-camera-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-slate-950/80 p-4" aria-hidden="true">
        <div class="w-full max-w-4xl overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div><h3 class="font-bold text-slate-800">Kamera LJK</h3><p class="text-xs text-slate-500">Posisikan seluruh kertas dan empat marker di dalam frame.</p></div>
                <button type="button" id="ljk-camera-close" class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100" aria-label="Tutup kamera">✕</button>
            </div>
            <div class="bg-black p-3"><video id="ljk-camera-video" class="mx-auto max-h-[70vh] w-full rounded-xl bg-black object-contain" autoplay playsinline muted></video></div>
            <div class="flex flex-col gap-3 border-t border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                <span id="ljk-camera-hint" class="text-xs text-slate-500">Gunakan cahaya merata dan hindari bayangan tangan.</span>
                <div class="flex gap-2">
                    <button type="button" id="ljk-camera-cancel" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Batal</button>
                    <button type="button" id="ljk-camera-capture" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-700">📸 Ambil Foto</button>
                </div>
            </div>
        </div>
    </div>
</div>

<meta name="csrf-token" content="{{ csrf_token() }}">
<script src="{{ asset('js/ljk/ljk-a4-v3-calibration.js') }}?v=13R11R1"></script>
<script src="{{ asset('js/ljk/ljk-scanner-core-v13r11.js') }}?v=13R11R1"></script>
<script src="{{ asset('js/ljk/ljk-laptop-v13r11.js') }}?v=13R11R1"></script>
</x-app-layout>
