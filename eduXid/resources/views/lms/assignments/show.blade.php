<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Detail Aktivitas</h2>
                <p class="text-sm text-gray-500 mt-0.5">Informasi aktivitas pembelajaran pada LMS.</p>
            </div>
            <a href="{{ route('lms.courses.show', $assignment->topic->course) }}"
               class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">
                ← Kembali ke Kelas
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            {{-- ================= PESAN ================= --}}
            @if (session('success'))
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 shadow-sm">{{ session('success') }}</div>
            @endif
            @if (session('warning'))
                <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-4 shadow-sm">⚠ {{ session('warning') }}</div>
            @endif
            @if (session('sync_success'))
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4 shadow-sm font-semibold">✓ {{ session('sync_success') }}</div>
            @endif
            @if (session('sync_warning'))
                <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-4 shadow-sm font-semibold">⚠ {{ session('sync_warning') }}</div>
            @endif

            {{-- ================= INFORMASI AKTIVITAS ================= --}}
            <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden mb-6">
                <div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-indigo-500">{{ $assignment->topic->title }}</p>
                            <h1 class="text-2xl font-bold text-indigo-900 mt-1">{{ $assignment->title }}</h1>
                        </div>
                        @if($assignment->is_published)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700">Dipublikasikan</span>
                        @else
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600">Draft</span>
                        @endif
                    </div>
                </div>

                <div class="p-6">
                    {{-- STATISTIK --}}
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="border border-gray-200 rounded-lg p-4">
                            <p class="text-xs text-gray-500">Jenis Aktivitas</p>
                            <p class="font-bold text-gray-800 mt-1">{{ $assignment->assignment_type === 'cbt' ? 'CBT / Ujian' : 'Tugas' }}</p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <p class="text-xs text-gray-500">Nilai Maksimal</p>
                            <p class="font-bold text-gray-800 mt-1">{{ rtrim(rtrim(number_format((float) $assignment->max_score, 2, ',', '.'), '0'), ',') }}</p>
                        </div>
                        <div class="border border-gray-200 rounded-lg p-4">
                            <p class="text-xs text-gray-500">Batas Pengumpulan</p>
                            <p class="font-bold text-gray-800 mt-1">{{ $assignment->due_at ? $assignment->due_at->format('d/m/Y H:i') : 'Tidak dibatasi' }}</p>
                        </div>
                    </div>

                    {{-- INSTRUKSI --}}
                    <div class="mb-6">
                        <h3 class="font-bold text-gray-800 mb-2">Instruksi</h3>
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700 whitespace-pre-line">
                            {{ $assignment->instructions ?: 'Tidak ada instruksi.' }}
                        </div>
                    </div>

                    {{-- ================= INFORMASI CBT ================= --}}
                    @if($assignment->assignment_type === 'cbt' && $assignment->exam)
                        <div class="border border-indigo-200 bg-indigo-50 rounded-xl p-5 mb-6">
                            <div class="flex items-start gap-3">
                                <div class="text-2xl">📝</div>
                                <div class="flex-1">
                                    <h3 class="font-bold text-indigo-900">Aktivitas CBT</h3>
                                    <p class="text-sm text-indigo-700 mt-1">
                                        Terhubung dengan ujian <b>{{ $assignment->exam->judul_ujian }}</b>.
                                    </p>
                                    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                                        <div class="bg-white border border-indigo-100 rounded-lg p-3">
                                            <p class="text-xs text-gray-500">Durasi</p>
                                            <p class="font-bold text-gray-800 mt-1">{{ $assignment->exam->durasi_menit > 0 ? $assignment->exam->durasi_menit.' menit' : 'Tanpa batas' }}</p>
                                        </div>
                                        <div class="bg-white border border-indigo-100 rounded-lg p-3">
                                            <p class="text-xs text-gray-500">Jumlah Soal</p>
                                            <p class="font-bold text-gray-800 mt-1">{{ $assignment->exam->questions->count() }}</p>
                                        </div>
                                        <div class="bg-white border border-indigo-100 rounded-lg p-3">
                                            <p class="text-xs text-gray-500">Status Ujian</p>
                                            <p class="font-bold mt-1 {{ $assignment->exam->status === 'aktif' ? 'text-green-600' : 'text-gray-600' }}">{{ ucfirst($assignment->exam->status) }}</p>
                                        </div>
                                    </div>

                                    <div class="mt-4 text-xs text-indigo-600 space-y-1">
                                        <p>
                                            Token: <span class="font-mono font-bold">{{ $assignment->exam->token }}</span>
                                            · ID Ujian: <span class="font-bold">{{ $assignment->exam->id }}</span>
                                        </p>
                                        <p class="text-indigo-700 bg-white border border-indigo-100 rounded p-2">
                                            Jawaban CBT <b>tidak dinilai di halaman ini</b> — koreksi &amp; konfirmasi dilakukan di halaman koreksi Modul CBT. Setelah dikonfirmasi: nilai masuk buku nilai harian dan terkirim ke murid (angka + feedback).
                                        </p>
                                    </div>

                                    @if(Route::has('guru.cbt.koreksi'))
                                        <div class="mt-3 flex gap-2 flex-wrap">
                                            <a href="{{ route('guru.cbt') }}"
                                               class="inline-flex items-center gap-1 bg-indigo-600 text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors">
                                                📝 Buka Modul CBT
                                            </a>
                                            <a href="{{ route('guru.cbt.koreksi', ['exam_id' => $assignment->exam_id]) }}"
                                               class="inline-flex items-center gap-1 bg-black text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-gray-800 transition-colors">
                                                🧾 Halaman Koreksi CBT
                                            </a>
                                        </div>
                                    @endif

                                    {{-- [F5-UX] Panel koreksi langsung: per siswa + otomatis --}}
                                    @if(isset($pendingCbtSessions) && $pendingCbtSessions->count() > 0)
                                        <div class="mt-4 bg-amber-50 border border-amber-300 rounded-lg p-4">
                                            <h4 class="font-bold text-amber-800 mb-2">
                                                🧾 Menunggu Koreksi ({{ $pendingCbtSessions->count() }})
                                            </h4>
                                            @foreach($pendingCbtSessions as $ps)
                                                <div class="flex items-center justify-between gap-3 bg-white rounded p-2 mb-2 border border-amber-200">
                                                    <span class="text-sm">
                                                        <b>{{ $ps->nama }}</b>
                                                        <span class="text-xs text-gray-500">({{ $ps->nisn }})</span>
                                                        · {{ \Carbon\Carbon::parse($ps->waktu_submit)->translatedFormat('d M H:i') }}
                                                    </span>
                                                    <a href="{{ route('guru.cbt.koreksi.show', ['session' => $ps->id]) }}"
                                                       class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-1.5 rounded text-xs font-semibold">
                                                        Koreksi &amp; Beri Nilai →
                                                    </a>
                                                </div>
                                            @endforeach

                                            @if(isset($cbtAutoGradeable) && $cbtAutoGradeable && Route::has('guru.cbt.koreksi.autoGrade'))
                                                <form method="POST" action="{{ route('guru.cbt.koreksi.autoGrade', $assignment->exam) }}"
                                                      class="mt-3"
                                                      onsubmit="return confirm('⚡ Koreksi OTOMATIS semua jawaban dengan kunci jawaban?\n\nNilai resmi masuk buku nilai harian & terkirim ke murid.')">
                                                    @csrf
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-1 bg-black text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-gray-800 transition-colors">
                                                        ⚡ Koreksi Otomatis (Kunci Jawaban) — {{ $pendingCbtSessions->count() }} murid
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @elseif($assignment->assignment_type === 'cbt')
                        <div class="border border-red-200 bg-red-50 rounded-xl p-5 mb-6">
                            <div class="flex items-start gap-3">
                                <div class="text-2xl">⚠️</div>
                                <div class="flex-1">
                                    <h3 class="font-bold text-red-800">Aktivitas CBT</h3>
                                    <div class="mt-2 bg-white border border-red-200 text-red-700 rounded-lg p-3 text-sm">
                                        Ujian CBT yang terhubung tidak ditemukan. Edit aktivitas ini untuk menghubungkan ulang paket ujian.
                                    </div>
                                    @if(Route::has('guru.cbt.koreksi'))
                                        <div class="mt-3">
                                            <a href="{{ route('guru.cbt.koreksi') }}"
                                               class="inline-flex items-center gap-1 bg-black text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-gray-800 transition-colors">
                                                🧾 Halaman Koreksi CBT
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ================= PENGUMPULAN SISWA ================= --}}
            <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden mb-6" id="submission-section">
                <div class="bg-indigo-50 px-6 py-5 border-b border-indigo-100">
                    <div class="flex items-center justify-between gap-3 flex-wrap">
                        <div>
                            <h3 class="text-lg font-bold text-indigo-900">Pengumpulan Murid</h3>
                            <p class="text-sm text-indigo-600 mt-1">
                                {{ $assignment->assignment_type === 'cbt'
                                    ? 'Salinan jawaban tertaut (biasanya kosong untuk CBT — koreksi di panel koreksi di atas).'
                                    : 'Daftar jawaban murid yang telah masuk ke LMS Guru.' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <div class="inline-flex items-center gap-2 bg-white border border-indigo-200 rounded-lg px-3 py-2">
                                <span class="text-xs text-gray-500">Total</span>
                                <span class="font-bold text-indigo-700">{{ $assignment->submissions->count() }}</span>
                            </div>
                            @if($assignment->assignment_type !== 'cbt' && $assignment->submissions->count() > 0 && Route::has('lms.assignments.submissions'))
                                <a href="{{ route('lms.assignments.submissions', $assignment) }}"
                                   class="inline-flex items-center gap-2 bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 text-xs font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">
                                    📝 Halaman Penilaian
                                </a>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    @if($assignment->submissions->count() > 0)
                        <div class="space-y-5">
                            @foreach($assignment->submissions as $submission)
                                @php
                                    /*
                                     * [NAME-FALLBACK] user → student_name (payload PWA) → student_id.
                                     */
                                    $namaSiswa =
                                        $submission->user->name
                                        ?? ($submission->student_name ?? null)
                                        ?? ($submission->student_id ?: 'Murid');

                                    /*
                                     * [GRADED-0] status = sumber kebenaran.
                                     */
                                    $isGraded = ($submission->status ?? '') === 'graded';

                                    /*
                                     * [GRADE-STATUS] Status kirim nilai ke siswa.
                                     */
                                    $gradeStatus = $submission->grade_status ?? null;

                                    /*
                                     * [ANSWER-PHOTO] Foto jawaban dari PWA di Cloud — buka lewat proxy guru.
                                     */
                                    $answerFileKey = trim((string) ($submission->answer_file_key ?? ''));
                                    $adaProxyFoto = Route::has('guru.lms.answer-file');
                                @endphp

                                <div class="border border-gray-200 rounded-xl overflow-hidden">
                                    <div class="bg-gray-50 px-5 py-4 border-b border-gray-200">
                                        <div class="flex items-start justify-between gap-4 flex-wrap">
                                            <div>
                                                <p class="text-xs uppercase tracking-wide text-gray-500">Murid</p>
                                                <h4 class="font-bold text-gray-800 mt-1">{{ $namaSiswa }}</h4>
                                                @if($submission->student_id)
                                                    <p class="text-xs text-gray-500 mt-1">
                                                        Login ID / NISN: <span class="font-mono font-semibold">{{ $submission->student_id }}</span>
                                                    </p>
                                                @endif
                                            </div>
                                            <div class="text-right">
                                                <p class="text-xs text-gray-500">Waktu Pengumpulan</p>
                                                <p class="text-sm font-semibold text-gray-700 mt-1">
                                                    {{ $submission->submitted_at ? $submission->submitted_at->format('d/m/Y H:i') : 'Belum tercatat' }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="p-5 space-y-5">
                                        {{-- STATUS & NILAI --}}
                                        <div class="flex items-center justify-between gap-3 flex-wrap">
                                            <div>
                                                <p class="text-xs text-gray-500">Status</p>
                                                <div class="mt-1 flex items-center gap-2 flex-wrap">
                                                    <span class="font-semibold text-gray-800">{{ ucfirst($submission->status ?: 'submitted') }}</span>
                                                    @if($isGraded && $gradeStatus)
                                                        @if($gradeStatus === 'synced')
                                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-green-50 text-green-600 border border-green-200">📤 ke murid ✓</span>
                                                        @elseif($gradeStatus === 'pending')
                                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-yellow-50 text-yellow-700 border border-yellow-200">⏳ nilai menunggu sinkron</span>
                                                        @elseif($gradeStatus === 'no_device')
                                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-600 border border-blue-200" title="Murid belum pairing — nilai terkirim otomatis setelah murid login di aplikasi">📱 menunggu perangkat murid</span>
                                                        @elseif($gradeStatus === 'rejected')
                                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-50 text-red-600 border border-red-200" title="Server menolak — coba simpan ulang">⚠ ditolak server</span>
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-xs text-gray-500">Nilai</p>
                                                <p class="text-xl font-bold text-indigo-700 mt-1">
                                                    @if($submission->score !== null)
                                                        {{ rtrim(rtrim(number_format((float) $submission->score, 2, ',', '.'), '0'), ',') }}
                                                    @else
                                                        -
                                                    @endif
                                                </p>
                                            </div>
                                        </div>

                                        {{-- JAWABAN TEKS --}}
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Jawaban</p>
                                            @if($submission->answer_text)
                                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-700 whitespace-pre-line">{{ $submission->answer_text }}</div>
                                            @else
                                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-sm text-gray-500 italic">Tidak ada jawaban teks.</div>
                                            @endif
                                        </div>

                                        {{-- [ANSWER-PHOTO] Foto dari Cloud --}}
                                        @if($answerFileKey !== '')
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Foto Jawaban (via Cloud)</p>
                                                <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 flex items-center justify-between gap-3 flex-wrap">
                                                    <p class="font-semibold text-gray-800 text-sm">📷 {{ $submission->answer_file_name ?? 'Foto jawaban murid' }}</p>
                                                    @if($adaProxyFoto)
                                                        <a href="{{ route('guru.lms.answer-file', ['submission' => $submission->id]) }}"
                                                           target="_blank" rel="noopener"
                                                           class="inline-flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-colors text-sm font-bold">
                                                            Buka Foto
                                                        </a>
                                                    @else
                                                        <span class="text-xs text-purple-600">Perbarui aplikasi guru untuk membuka foto (proxy unduhan belum terpasang).</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        {{-- FILE LOKAL --}}
                                        @if($submission->file_name)
                                            <div>
                                                <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">File Lampiran</p>
                                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                                    <div class="flex items-center justify-between gap-3 flex-wrap">
                                                        <div>
                                                            <p class="font-semibold text-gray-800">{{ $submission->file_name }}</p>
                                                            @if($submission->file_size)
                                                                <p class="text-xs text-gray-500 mt-1">{{ number_format($submission->file_size / 1024, 1, ',', '.') }} KB</p>
                                                            @endif
                                                        </div>
                                                        @if($submission->file_path)
                                                            <a href="{{ app(\App\Services\LocalMediaService::class)->url($submission->file_path) }}"
                                                               target="_blank" rel="noopener"
                                                               class="inline-flex items-center gap-2 bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors text-sm font-bold">
                                                                Buka File
                                                            </a>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        {{-- PENILAIAN & FEEDBACK --}}
                                        <div>
                                            <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">Penilaian &amp; Feedback</p>

                                            @if($submission->teacher_feedback)
                                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800 whitespace-pre-line mb-3">{{ $submission->teacher_feedback }}</div>
                                            @endif

                                            <form method="POST" action="{{ route('lms.submissions.grade', $submission) }}"
                                                  class="bg-indigo-50 border border-indigo-100 rounded-lg p-4">
                                                @csrf
                                                <div class="flex flex-wrap gap-3 items-end">
                                                    <div>
                                                        <label class="block text-xs font-bold text-gray-500 mb-1">
                                                            Nilai
                                                            <span class="font-normal text-gray-400">(maks. {{ rtrim(rtrim(number_format((float) $assignment->max_score, 2, ',', '.'), '0'), ',') }})</span>
                                                        </label>
                                                        <input type="number" name="score" step="0.01" min="0"
                                                               max="{{ $assignment->max_score }}"
                                                               value="{{ old('score', $submission->score) }}" required
                                                               class="w-28 rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-bold text-gray-500 mb-1">Masuk ke Nilai Harian</label>
                                                        <select name="score_type"
                                                                class="rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                            <option value="formatif"   {{ old('score_type', 'formatif') === 'formatif'   ? 'selected' : '' }}>Formatif</option>
                                                            <option value="sumatif"    {{ old('score_type') === 'sumatif'    ? 'selected' : '' }}>Sumatif</option>
                                                            <option value="diagnostik" {{ old('score_type') === 'diagnostik' ? 'selected' : '' }}>Diagnostik</option>
                                                        </select>
                                                    </div>
                                                    <div class="flex-1 min-w-[220px]">
                                                        <label class="block text-xs font-bold text-gray-500 mb-1">Feedback untuk murid</label>
                                                        <input type="text" name="teacher_feedback"
                                                               value="{{ old('teacher_feedback', $submission->teacher_feedback) }}"
                                                               placeholder="Masukan untuk murid..."
                                                               class="w-full rounded-lg border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                    </div>
                                                    <button type="submit"
                                                            class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition-colors text-sm font-bold shadow-sm">
                                                        @if($isGraded) ⟳ Perbarui Nilai @else 💾 Nilai &amp; Kirim ke Murid @endif
                                                    </button>
                                                </div>
                                                <p class="text-xs text-gray-500 mt-2">
                                                    Nilai otomatis tercatat di <span class="font-semibold">Nilai Harian</span>
                                                    pada tanggal pengumpulan murid
                                                    @if($submission->submitted_at) ({{ $submission->submitted_at->format('d/m/Y') }}) @endif
                                                    dengan materi "Tugas: {{ $assignment->title }}".
                                                    @if($isGraded)
                                                        Mengirim ulang menaikkan versi sinkronisasi — murid menerima nilai terbaru.
                                                    @endif
                                                    @if($gradeStatus === 'no_device')
                                                        <span class="text-blue-600 font-semibold">Murid ini belum memasang perangkat — nilai tersimpan dan terkirim otomatis setelah dia login di aplikasi.</span>
                                                    @endif
                                                </p>
                                            </form>
                                        </div>

                                        {{-- INFO SINKRONISASI --}}
                                        <div class="pt-4 border-t border-gray-100">
                                            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-xs">
                                                <div>
                                                    <span class="text-gray-500">Sync ID</span>
                                                    <p class="font-mono text-gray-700 mt-1 break-all">{{ $submission->sync_id }}</p>
                                                </div>
                                                <div>
                                                    <span class="text-gray-500">Versi Sync</span>
                                                    <p class="font-semibold text-gray-700 mt-1">v{{ $submission->sync_version }}</p>
                                                </div>
                                                <div>
                                                    <span class="text-gray-500">Status Sinkronisasi</span>
                                                    <p class="font-semibold text-gray-700 mt-1">{{ ucfirst($submission->sync_status ?: 'unknown') }}</p>
                                                </div>
                                                <div>
                                                    <span class="text-gray-500">Grade Status</span>
                                                    <p class="font-semibold text-gray-700 mt-1">{{ $gradeStatus ? ucfirst($gradeStatus) : '-' }}</p>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        {{-- [CBT-FLOW] Empty state jujur untuk CBT --}}
                        @if($assignment->assignment_type === 'cbt')
                            <div class="text-center py-10 border border-indigo-100 bg-indigo-50 rounded-xl">
                                <div class="text-4xl mb-3">📝</div>
                                <h4 class="font-bold text-indigo-900">Jawaban CBT dikoreksi di panel "Menunggu Koreksi" di atas</h4>
                                <p class="text-sm text-indigo-700 mt-1 max-w-md mx-auto">
                                    Murid mengerjakan CBT di runtime ujian (LAN atau PWA). Jawaban mereka masuk ke antrian koreksi — bukan ke daftar ini.
                                </p>
                                <div class="mt-4 flex gap-2 justify-center flex-wrap">
                                    <a href="{{ route('guru.cbt') }}"
                                       class="bg-indigo-600 text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-indigo-700 transition-colors">
                                        Buka Modul CBT
                                    </a>
                                    @if(Route::has('guru.cbt.koreksi'))
                                        <a href="{{ route('guru.cbt.koreksi', ['exam_id' => $assignment->exam_id ?? null]) }}"
                                           class="bg-black text-white text-xs font-bold px-4 py-2 rounded-lg hover:bg-gray-800 transition-colors">
                                            🧾 Halaman Koreksi
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div class="text-center py-10">
                                <div class="text-4xl mb-3">📭</div>
                                <h4 class="font-bold text-gray-700">Belum ada pengumpulan</h4>
                                <p class="text-sm text-gray-500 mt-1">Jawaban murid akan muncul di sini setelah diterima oleh LMS Guru.</p>
                            </div>
                        @endif
                    @endif
                </div>
            </div>

            {{-- ================= AKSI ================= --}}
            <div class="bg-white rounded-xl shadow-md border border-gray-100 px-6 py-4 flex justify-between items-center flex-wrap gap-3">
                <a href="{{ route('lms.courses.show', $assignment->topic->course) }}"
                   class="inline-flex items-center gap-2 bg-gray-100 text-gray-700 border border-gray-200 px-5 py-2.5 rounded-lg hover:bg-gray-200 transition-colors text-sm font-bold">
                    ← Kembali
                </a>

                <div class="flex items-center gap-2 flex-wrap">
                    @if($assignment->is_published)
                        <form method="POST" action="{{ route('lms.assignments.unpublish', $assignment) }}"
                              onsubmit="return confirm('Batalkan publikasi? Aktivitas disembunyikan dari perangkat murid{{ $assignment->assignment_type === 'cbt' ? ' — PAKET UJIAN CBT juga IKUT DITARIK dari perangkat murid' : '' }}.')">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 bg-yellow-500 text-white px-5 py-2.5 rounded-lg hover:bg-yellow-600 transition-colors text-sm font-bold">
                                Batalkan Publikasi
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('lms.assignments.publish', $assignment) }}"
                              onsubmit="return confirm('Publikasikan aktivitas{{ $assignment->assignment_type === 'cbt' ? ' (paket ujian ikut dikirim ke murid)' : '' }}?')">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 bg-green-600 text-white px-5 py-2.5 rounded-lg hover:bg-green-700 transition-colors text-sm font-bold">
                                Publikasikan
                            </button>
                        </form>
                    @endif

                    <a href="{{ route('lms.assignments.edit', $assignment) }}"
                       class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition-colors text-sm font-bold">
                        Edit Aktivitas
                    </a>

                    @if(Route::has('lms.assignments.destroy'))
                        <form method="POST" action="{{ route('lms.assignments.destroy', $assignment) }}"
                              onsubmit="return confirm('Hapus aktivitas ini? Perintah hapus akan dikirim ke perangkat murid.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit"
                                    class="inline-flex items-center gap-2 bg-gray-500 text-white px-5 py-2.5 rounded-lg hover:bg-gray-700 transition-colors text-sm font-bold">
                                Hapus
                            </button>
                        </form>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-app-layout>