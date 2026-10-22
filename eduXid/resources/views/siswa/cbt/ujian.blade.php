<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT - {{ $exam->judul_ujian }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
    <script defer src="{{ asset('katex/katex.min.js') }}"></script>
    <script defer src="{{ asset('katex/contrib/auto-render.min.js') }}"></script>

    <style>[x-cloak]{display:none!important;}</style>
</head>
<body class="bg-gray-50">

<div x-data="cbtEngine({
        sessionId: {{ $session->id }},
        duration: {{ (int) $duration }},
        remainingSeconds: {{ (int) $remainingSeconds }},
        autosaveUrl: '{{ route('cbt.autoSave', $session->id) }}',
        questions: {{ Illuminate\Support\Js::from($questions) }},
        answers: {{ Illuminate\Support\Js::from($savedAnswers) }}
    })"
    x-cloak
    x-init="init()"
    class="pb-20">

    <!-- Form Submit: mengirim SEMUA jawaban sebagai JSON -->
    <form id="submitForm" action="{{ route('cbt.submit', $session->id) }}" method="POST">
        @csrf
        <input type="hidden" name="answers_payload" :value="JSON.stringify(answers)">
    </form>

    <!-- Header / Timer -->
    <div class="bg-white shadow-sm sticky top-0 z-50 border-b border-gray-100">
        <div class="container mx-auto px-4 py-3 flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="text-center sm:text-left">
                <h1 class="font-bold text-base sm:text-lg text-gray-800 leading-tight">{{ $exam->judul_ujian }}</h1>
                <p class="text-xs sm:text-sm text-gray-500">
                    <span class="font-semibold text-gray-700">{{ $session->student->nama }}</span>
                    (NISN: {{ $session->student->nisn }})
                </p>
            </div>
            <div class="flex items-center gap-4">
                <span x-show="savedAt" x-cloak class="text-xs text-green-600 hidden sm:inline">💾 Draft tersimpan</span>
                <template x-if="hasTimer">
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-gray-500 hidden sm:inline">Sisa Waktu:</span>
                        <span x-text="formattedTime"
                              class="font-mono text-xl font-bold px-3 py-1 rounded"
                              :class="remainingSeconds < 300 ? 'bg-red-100 text-red-600 animate-pulse' : 'bg-blue-100 text-blue-600'"></span>
                    </div>
                </template>
                <template x-if="!hasTimer">
                    <span class="text-sm text-gray-500">Tanpa batas waktu</span>
                </template>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-6 flex flex-col lg:flex-row gap-6">
        <!-- Navigasi Soal -->
        <div class="lg:w-1/4 order-2 lg:order-1">
            <div class="bg-white p-4 rounded-lg shadow-sm sticky top-20">
                <h3 class="font-bold text-gray-700 mb-3">Navigasi Soal</h3>

                @if(count($questions) > 0)
                <div class="grid grid-cols-5 gap-2">
                    <template x-for="(q, index) in questions" :key="q.id">
                        <button type="button" @click="goTo(index)"
                                x-text="index + 1"
                                :class="{
                                    'bg-blue-600 text-white': currentQuestion === index,
                                    'bg-green-100 text-green-700 border border-green-500': isAnswered(q.id) && currentQuestion !== index,
                                    'bg-gray-100 text-gray-600': !isAnswered(q.id) && currentQuestion !== index
                                }"
                                class="w-10 h-10 rounded font-bold transition">
                        </button>
                    </template>
                </div>
                @else
                <p class="text-red-500 text-sm">Tidak ada soal di paket ujian ini.</p>
                @endif

                <button type="button" @click="submitExam(false)" :disabled="submitting"
                        class="w-full mt-6 bg-red-500 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-600 transition-colors disabled:opacity-50">
                    <span x-text="submitting ? 'Mengirim...' : 'Selesai & Kumpulkan'"></span>
                </button>

                <p class="text-xs text-gray-400 mt-3 text-center">Jawaban tersimpan otomatis berkala di server.</p>
            </div>
        </div>

        <!-- Konten Soal -->
        <div class="lg:w-3/4 order-1 lg:order-2">
            <div class="bg-white p-6 rounded-lg shadow-sm min-h-[400px]">
                <template x-if="questions.length > 0">
                    <div>
                        <p class="text-sm text-gray-500 mb-1">
                            Soal No. <span x-text="currentQuestion + 1"></span> dari <span x-text="questions.length"></span>
                            · bobot <span x-text="questions[currentQuestion].bobot_nilai"></span>
                            · <span class="uppercase" x-text="questions[currentQuestion].jenis_soal ?? ''"></span>
                        </p>

                        <!-- Render Soal (konten guru: HTML + gambar) -->
                        <div class="prose max-w-none mb-6 text-lg cbt-question-content" x-show="!questions[currentQuestion].tka_category" x-html="questions[currentQuestion].question"></div>
                        <template x-if="groupImage(questions[currentQuestion])">
                            <img :src="groupImage(questions[currentQuestion])"
                                 alt="Gambar pendukung soal CBT"
                                 class="mb-6 max-w-full max-h-80 object-contain rounded border border-gray-200">
                        </template>

                        <template x-if="questions[currentQuestion].tka_group_code && questions[currentQuestion].tka_stimulus">
                            <div class="mb-5 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm whitespace-pre-line" x-text="questions[currentQuestion].tka_stimulus"></div>
                        </template>
                        <template x-if="questions[currentQuestion].tka_category">
                            <div class="overflow-auto mb-5 rounded-lg border border-slate-200">
                                <table class="w-full text-sm border-collapse">
                                    <thead class="bg-slate-100"><tr><th class="p-3 text-left">Pernyataan</th><th class="p-3">Benar</th><th class="p-3">Salah</th></tr></thead>
                                    <tbody>
                                        <template x-for="item in categoryItems(questions[currentQuestion])" :key="item.id">
                                            <tr class="border-t"><td class="p-3" x-text="item.question"></td>
                                                <td class="p-3 text-center"><input type="radio" :name="'tka-'+item.id" value="B" :checked="answers[item.id]==='B'" @change="setAnswer(item.id,'B')"></td>
                                                <td class="p-3 text-center"><input type="radio" :name="'tka-'+item.id" value="S" :checked="answers[item.id]==='S'" @change="setAnswer(item.id,'S')"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <p class="text-xs text-slate-600 p-3">Berikan jawaban untuk setiap pernyataan.</p>
                            </div>
                        </template>
                        <!-- Pilihan -->
                        <div class="space-y-3" x-show="!questions[currentQuestion].tka_category">
                            <template x-for="opt in questions[currentQuestion].options" :key="opt.key">
                                <label class="flex items-start gap-3 p-3 border rounded-lg cursor-pointer transition select-none"
                                       :class="isChecked(questions[currentQuestion], opt.key) ? 'bg-blue-50 border-blue-500' : 'hover:bg-gray-50'">

                                    <!-- [MAJEMUK] Checkbox — jawaban array -->
                                    <input x-if="isMajemuk(questions[currentQuestion])"
                                           type="checkbox"
                                           :name="'q'+questions[currentQuestion].id"
                                           :value="opt.key"
                                           :checked="isChecked(questions[currentQuestion], opt.key)"
                                           @change="toggleMajemuk(questions[currentQuestion].id, opt.key)"
                                           class="mt-1 w-4 h-4">

                                    <!-- PG / Benar-Salah: Radio -->
                                    <input x-show="!isMajemuk(questions[currentQuestion])"
                                           type="radio"
                                           :name="'q'+questions[currentQuestion].id"
                                           :value="opt.key"
                                           :checked="answers[questions[currentQuestion].id] === opt.key"
                                           @change="setAnswer(questions[currentQuestion].id, opt.key)"
                                           class="mt-1 w-4 h-4">

                                    <span x-html="opt.text"></span>
                                </label>
                            </template>
                        </div>

                        <!-- Esai (tanpa opsi) -->
                        <template x-if="questions[currentQuestion].options.length === 0 && !questions[currentQuestion].tka_category">
                            <textarea rows="6"
                                      :value="answers[questions[currentQuestion].id] ?? ''"
                                      @input="setAnswer(questions[currentQuestion].id, $event.target.value)"
                                      placeholder="Tulis jawaban Anda di sini..."
                                      class="w-full border rounded-lg p-3 focus:outline-none focus:ring-2 focus:ring-blue-400"></textarea>
                        </template>

                        <p x-show="isMajemuk(questions[currentQuestion])" class="text-xs text-gray-500 mt-3">
                            Pilih semua jawaban yang benar (boleh lebih dari satu).
                        </p>

                        <!-- Navigasi Bawah -->
                        <div class="flex justify-between mt-8">
                            <button type="button" @click="prev()" :disabled="currentQuestion === 0 || submitting"
                                    class="px-4 py-2 bg-gray-200 rounded hover:bg-gray-300 disabled:opacity-50">← Sebelumnya</button>
                            <button type="button" @click="next()" :disabled="currentQuestion === questions.length - 1 || submitting"
                                    class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">Selanjutnya →</button>
                        </div>
                    </div>
                </template>

                <template x-if="questions.length === 0">
                    <div class="text-center text-gray-500 py-10">
                        <p>Soal tidak ditemukan. Hubungi guru Anda.</p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>

<footer class="bg-white border-t border-gray-100 mt-10 py-4 text-center text-xs text-gray-400">
    &copy; {{ date('Y') }}. Anas Farhan. eduXid untuk Indonesia.
</footer>

<script>
function cbtEngine(data) {
    return {
        ...data,

        currentQuestion: 0,
        submitting: false,
        savedAt: null,
        lastSavedJson: '{}',
        autosaveTimer: null,

        /*
         * [TIMER-FIX] Sisa waktu dari SERVER (waktu_masuk + durasi),
         * bukan duration*60 — refresh halaman tidak lagi mereset waktu.
         * durasi_menit = 0 (tanpa batas) → tanpa timer & tanpa auto-submit.
         */
        hasTimer: data.duration > 0,
        remainingSeconds: data.hasTimer !== undefined ? data.remainingSeconds : data.remainingSeconds,

        init() {
            /*
             * [MAJEMUK] Jawaban tersimpan untuk soal majemuk berupa
             * JSON string '["A","C"]' — parse ke array saat init.
             */
            for (const q of this.questions) {
                if (this.isMajemuk(q)) {
                    const raw = this.answers[q.id];
                    if (typeof raw === 'string' && raw.trim().startsWith('[')) {
                        try { this.answers[q.id] = JSON.parse(raw); } catch (e) { this.answers[q.id] = []; }
                    } else if (raw == null) {
                        this.answers[q.id] = [];
                    }
                }
            }

            this.lastSavedJson = JSON.stringify(this.answers);
            this.renderMath();

            if (this.hasTimer) { this.startTimer(); }
            this.startAutosave();

            /* [ANTI-CHEAT] Ringan, selaras PWA. */
            ['copy','cut','paste','contextmenu'].forEach(ev =>
                document.addEventListener(ev, (e) => { e.preventDefault(); }, true)
            );

            /* Simpan saat tab disembunyikan / ditutup (keepalive). */
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') { this.autosave(true); }
            });
            window.addEventListener('pagehide', () => { this.autosave(true); });

            window.addEventListener('beforeunload', (e) => {
                if (this.submitting) { return; }
                this.autosave(true);
                if (this.hasUnsavedChanges()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },

        /* ---------- helpers jawaban ---------- */

        groupImage(q) {
            if (!q) return null;
            if (q.gambar) return q.gambar;
            if (!q.tka_group_code) return null;
            const source = this.questions.find(item => item.tka_group_code === q.tka_group_code && item.gambar);
            return source ? source.gambar : null;
        },

        categoryItems(q) {
            return this.questions.filter(item => item.tka_category && item.tka_group_code && item.tka_group_code === q.tka_group_code)
                .sort((a,b) => (a.tka_statement_order || 0) - (b.tka_statement_order || 0));
        },

        isMajemuk(q) {
            return String(q.jenis_soal ?? '').toLowerCase().includes('majemuk');
        },

        /*
         * [NAV-FIX] Array kosong [] truthy di JS — dulu navigasi
         * menandai soal majemuk kosong sebagai terjawab.
         */
        isAnswered(qid) {
            const a = this.answers[qid];
            if (a == null) return false;
            if (Array.isArray(a)) return a.length > 0;
            return String(a) !== '';
        },

        isChecked(q, key) {
            const a = this.answers[q.id];
            if (this.isMajemuk(q)) { return Array.isArray(a) && a.includes(key); }
            return a === key;
        },

        setAnswer(qid, value) {
            this.answers[qid] = value;
        },

        toggleMajemuk(qid, key) {
            const arr = Array.isArray(this.answers[qid]) ? this.answers[qid].slice() : [];
            const i = arr.indexOf(key);
            if (i >= 0) { arr.splice(i, 1); } else { arr.push(key); }
            arr.sort();
            this.answers[qid] = arr;
        },

        /* ---------- navigasi ---------- */

        goTo(index) {
            this.currentQuestion = index;
            this.renderMath();
        },
        next() {
            if (this.currentQuestion < this.questions.length - 1) { this.goTo(this.currentQuestion + 1); }
        },
        prev() {
            if (this.currentQuestion > 0) { this.goTo(this.currentQuestion - 1); }
        },

        /* ---------- math ---------- */

        renderMath() {
            this.$nextTick(() => {
                if (typeof renderMathInElement !== 'undefined') {
                    renderMathInElement(document.body, {
                        delimiters: [
                            { left: "$$", right: "$$", display: true },
                            { left: "$", right: "$", display: false }
                        ],
                        throwOnError: false
                    });
                }
            });
        },

        /* ---------- timer ---------- */

        get formattedTime() {
            const s = Math.max(0, this.remainingSeconds);
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const sec = s % 60;
            return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
        },

        startTimer() {
            setInterval(() => {
                if (this.submitting) { return; }
                this.remainingSeconds--;
                if (this.remainingSeconds <= 0) {
                    this.remainingSeconds = 0;
                    /* Auto-submit tanpa konfirmasi — waktu habis. */
                    this.submitExam(true);
                }
            }, 1000);
        },

        /* ---------- autosave ---------- */

        hasUnsavedChanges() {
            return JSON.stringify(this.answers) !== this.lastSavedJson;
        },

        startAutosave() {
            this.autosaveTimer = setInterval(() => { this.autosave(false); }, 15000);
        },

        async autosave(keepalive) {
            if (this.submitting) { return; }

            const payload = JSON.stringify(this.answers);

            if (!this.hasUnsavedChanges() && !keepalive) { return; }

            try {
                const res = await fetch(this.autosaveUrl, {
                    method: 'POST',
                    keepalive: !!keepalive,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ answers_payload: payload }),
                });

                if (res.ok) {
                    this.lastSavedJson = payload;
                    this.savedAt = new Date();
                    setTimeout(() => { this.savedAt = null; }, 4000);
                    return;
                }

                /*
                 * Server menolak: waktu habis / ujian dinonaktifkan /
                 * sesi tidak valid → serahkan ke submit (server adalah
                 * sumber kebenaran durasi).
                 */
                if (res.status === 403 || res.status === 401) {
                    let body = null;
                    try { body = await res.json(); } catch (e) { /* ignore */ }
                    if (body && body.duration_exceeded) {
                        this.submitExam(true);
                    }
                }
            } catch (e) {
                /* Offline/putus — autosave berikutnya mencoba lagi; jawaban tetap di memori. */
            }
        },

        /* ---------- submit ---------- */

        /*
         * [CONFIRM-FIX] confirm() kini di sini — dulu di onsubmit
         * form, yang TIDAK PERNAH terpicu karena submit programatik
         * (form.submit()) melewati handler onsubmit.
         */
        submitExam(auto) {
            if (this.submitting) { return; }

            if (!auto) {
                const unanswered = this.questions.filter(q => !this.isAnswered(q.id)).length;
                const msg = unanswered > 0
                    ? `Masih ada ${unanswered} soal belum dijawab.\n\nYakin ingin mengumpulkan jawaban? Anda tidak bisa mengubahnya lagi.`
                    : 'Yakin ingin mengumpulkan jawaban? Anda tidak bisa mengubahnya lagi.';

                if (!window.confirm(msg)) { return; }
            } else {
                window.alert('Waktu habis. Jawaban dikumpulkan otomatis.');
            }

            this.submitting = true;
            if (this.autosaveTimer) { clearInterval(this.autosaveTimer); }

            document.getElementById('submitForm').submit();
        }
    }
}
</script>

<style>
    /* Gambar soal responsif di layar HP */
    .cbt-question-content img {
        max-width: 100% !important;
        height: auto !important;
        display: block;
        margin-left: auto;
        margin-right: auto;
    }
</style>

</body>
</html>