<x-app-layout>

<x-slot name="header">

    <div class="flex items-center justify-between flex-wrap gap-3">

        <div class="min-w-0">

            <p class="text-xs text-indigo-500 font-bold uppercase tracking-wide">
                Penilaian Aktivitas
            </p>

            <h2 class="font-semibold text-xl text-gray-800 leading-tight mt-1 truncate">
                {{ $assignment->title }}
            </h2>

        </div>

        <a href="{{ route('lms.assignments.show', $assignment) }}"
           class="inline-flex items-center gap-2 bg-white border border-gray-300 text-gray-700
                  text-sm font-bold px-4 py-2 rounded-lg shadow-sm transition-colors">
            ← Kembali ke Aktivitas
        </a>

    </div>

</x-slot>

<div class="py-12">

    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

        {{-- ================= INFO AKTIVITAS ================= --}}

        <div class="bg-indigo-50 border border-indigo-100 rounded-2xl px-6 py-5 mb-4">

            <div class="flex items-center justify-between gap-3 flex-wrap">

                <div>

                    <p class="text-sm font-bold text-indigo-900">
                        {{ $assignment->topic->title ?? '-' }}
                    </p>

                    <p class="text-xs text-indigo-600 mt-1">
                        Nilai maksimal:
                        <span class="font-bold">{{ number_format((float) $assignment->max_score, 0, ',', '.') }}</span>
                        · Total submission:
                        <span class="font-bold">{{ $submissions->count() }}</span>
                        @if($gradedCount !== null)
                            · Sudah dinilai:
                            <span class="font-bold">{{ $gradedCount }}</span>
                            · Belum:
                            <span class="font-bold">{{ $submissions->count() - $gradedCount }}</span>
                        @endif
                    </p>

                </div>

            </div>

        </div>

        @if(session('sync_success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4 text-sm font-semibold shadow-sm">
                ✓ {{ session('sync_success') }}
            </div>
        @endif

        @if(session('sync_warning'))
            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4 text-sm font-semibold shadow-sm">
                ⚠ {{ session('sync_warning') }}
            </div>
        @endif

        @if(session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl mb-4 text-sm font-semibold shadow-sm">
                ✓ {{ session('success') }}
            </div>
        @endif

        @if(session('warning'))
            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-xl mb-4 text-sm font-semibold shadow-sm">
                ⚠ {{ session('warning') }}
            </div>
        @endif

        {{-- ================= DAFTAR SUBMISSION ================= --}}

        @if($submissions->isEmpty())

            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-12 text-center">
                <div class="text-5xl mb-3">📭</div>
                <h3 class="font-bold text-gray-700">Belum ada submission</h3>
                <p class="text-sm text-gray-500 mt-1">
                    Jawaban murid akan muncul di sini setelah tersinkronisasi dari perangkat mereka.
                </p>
            </div>

        @else

            <div class="space-y-5">

                @foreach($submissions as $submission)

                    @php
                        /*
                         * [GRADED-0] "Dinilai" = status graded (sumber
                         * kebenaran), BUKAN sekadar score !== null —
                         * nilai 0 sah dan dulu terhitung belum dinilai.
                         */
                        $isGraded = $submission->status === 'graded';

                        /*
                         * [GRADE-STATUS] Status pengiriman nilai ke
                         * perangkat siswa (ditulis PWA Guru / LWW).
                         * no_device = siswa belum pairing — bukan error.
                         */
                        $gradeStatus = $submission->grade_status ?? null;

                        $studentName =
                            $studentNames[$submission->student_id]
                            ?? ($submission->student_name ?? null)
                            ?? $submission->student_id;

                        /*
                         * [ANSWER-PHOTO] Foto jawaban dari PWA siswa
                         * tersimpan di Cloud (answer_file_key), bukan di
                         * storage laptop — link berbeda dari file lokal.
                         */
                        $answerFileKey = trim((string) ($submission->answer_file_key ?? ''));
                        $answerUrl = ($answerFileKey !== '' && Route::has('guru.lms.answer-file'))
                            ? route('guru.lms.answer-file', ['submission' => $submission->id])
                            : null;

                        /*
                         * [TARGET-HP] Device tujuan nilai: {school}-{NISN}-HP.
                         * Dulu teks menuliskan SISWA-{id}-HP yang tidak ada
                         * dalam kontrak sistem.
                         */
                        $nisn = $submission->student_nisn ?? $submission->student_id;
                        $schoolId = function_exists('get_school_id') ? get_school_id() : (config('eduxid.school_id') ?? '');
                        $targetHp = trim((string) $schoolId) !== ''
                            ? $schoolId . '-' . $nisn . '-HP'
                            : '{sekolah}-' . $nisn . '-HP';
                    @endphp

                    <div class="student-card bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden" data-eduxid-student-nisn="{{ preg_match('/^[0-9]{10}$/D', (string) $nisn) ? $nisn : '' }}" data-eduxid-student-name="{{ $studentName }}">

                        {{-- HEADER SISWA --}}
                        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">

                            <div>
                                <p class="font-bold text-gray-800">{{ $studentName }}</p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    login_id:
                                    <span class="font-mono font-semibold">{{ $submission->student_id }}</span>
                                    @if($submission->submitted_at)
                                        · dikumpulkan {{ $submission->submitted_at->format('d/m/Y H:i') }}
                                    @endif
                                </p>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-3 py-1 rounded-full text-xs font-bold {{ $isGraded
                                    ? 'bg-green-50 text-green-700 border border-green-200'
                                    : 'bg-blue-50 text-blue-700 border border-blue-200' }}">
                                    {{ $isGraded ? 'Dinilai: ' . $submission->score : 'Menunggu penilaian' }}
                                </span>

                                {{-- [GRADE-STATUS] Chip status kirim ke siswa --}}
                                @if($isGraded && $gradeStatus)
                                    @if($gradeStatus === 'synced')
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold bg-green-50 text-green-600 border border-green-200">📤 ke murid ✓</span>
                                    @elseif($gradeStatus === 'pending')
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold bg-yellow-50 text-yellow-700 border border-yellow-200">⏳ nilai menunggu sinkron</span>
                                    @elseif($gradeStatus === 'no_device')
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold bg-blue-50 text-blue-600 border border-blue-200" title="Murid belum memasang perangkat — nilai tersimpan & terkirim otomatis setelah murid pairing">📱 menunggu perangkat murid</span>
                                    @elseif($gradeStatus === 'rejected')
                                        <span class="px-2 py-1 rounded-full text-[10px] font-bold bg-red-50 text-red-600 border border-red-200" title="Server menolak nilai — coba simpan ulang">⚠ ditolak server</span>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{-- JAWABAN --}}
                        <div class="px-6 py-4">

                            <p class="text-xs font-bold text-gray-400 uppercase tracking-wide mb-2">Jawaban</p>

                            <div class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-sm text-gray-700 whitespace-pre-line">
                                {{ $submission->answer_text ?? '(tanpa jawaban teks)' }}
                            </div>

                            {{-- [ANSWER-PHOTO] Foto dari Cloud (PWA siswa) --}}
                            @if($answerUrl)
                                <div class="mt-3 bg-purple-50 border border-purple-200 rounded-xl p-4 flex items-center justify-between gap-3 flex-wrap">
                                    <p class="font-semibold text-gray-800 text-sm">📷 Foto jawaban (terkirim via Cloud)</p>
                                    <a href="{{ $answerUrl }}" target="_blank" rel="noopener"
                                       class="inline-flex items-center gap-2 bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-colors text-sm font-bold">
                                        Buka Foto
                                    </a>
                                </div>
                            @endif

                            {{-- File lokal (upload dari laptop, jika ada) --}}
                            @if($submission->file_name)
                                <div class="mt-3 bg-gray-50 border border-gray-200 rounded-xl p-4 flex items-center justify-between gap-3 flex-wrap">

                                    <div>
                                        <p class="font-semibold text-gray-800 text-sm">📎 {{ $submission->file_name }}</p>
                                        @if($submission->file_size)
                                            <p class="text-xs text-gray-500 mt-1">
                                                {{ number_format($submission->file_size / 1024, 1, ',', '.') }} KB
                                            </p>
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
                            @endif

                        </div>

                        {{-- FORM PENILAIAN --}}
                        <form method="POST" action="{{ route('lms.submissions.grade', $submission) }}"
                              class="px-6 py-4 bg-indigo-50 border-t border-indigo-100">

                            @csrf

                            <div class="flex flex-wrap gap-3 items-end">

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Nilai</label>
                                    <input type="number" name="score" step="0.01" min="0"
                                           max="{{ $assignment->max_score }}"
                                           value="{{ old('score', $submission->score) }}"
                                           required
                                           class="w-28 rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <div>
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Masuk ke Nilai Harian</label>
                                    <select name="score_type"
                                            class="rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="formatif"   {{ old('score_type', 'formatif') === 'formatif'   ? 'selected' : '' }}>Formatif (harian)</option>
                                        <option value="sumatif"    {{ old('score_type') === 'sumatif'    ? 'selected' : '' }}>Sumatif</option>
                                        <option value="diagnostik" {{ old('score_type') === 'diagnostik' ? 'selected' : '' }}>Diagnostik</option>
                                    </select>
                                </div>

                                <div class="flex-1 min-w-[220px]">
                                    <label class="block text-xs font-bold text-gray-500 mb-1">Feedback untuk murid</label>
                                    <input type="text" name="teacher_feedback"
                                           value="{{ old('teacher_feedback', $submission->teacher_feedback) }}"
                                           placeholder="Masukan untuk murid..."
                                           class="w-full rounded-xl border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>

                                <button type="submit"
                                        class="inline-flex items-center gap-2 bg-indigo-600 text-white px-5 py-2.5 rounded-xl hover:bg-indigo-700 transition-colors text-sm font-bold shadow-sm">
                                    @if($isGraded) ⟳ Perbarui Nilai @else 💾 Nilai &amp; Kirim ke Murid @endif
                                </button>
                            </div>

                            <p class="text-xs text-gray-500 mt-2">
                                Nilai otomatis tercatat di <span class="font-semibold">Nilai Harian</span>
                                pada tanggal pengumpulan murid
                                @if($submission->submitted_at) ({{ $submission->submitted_at->format('d/m/Y') }}) @endif
                                dan terkirim ke perangkat murid
                                (<span class="font-mono">{{ $targetHp }}</span>).
                                @if($gradeStatus === 'no_device')
                                    <span class="text-blue-600 font-semibold">Murid ini belum memasang perangkat — nilai tersimpan dan akan terkirim otomatis setelah dia login di aplikasi.</span>
                                @endif
                            </p>
                        </form>

                        {{-- INFO SINKRONISASI --}}
                        <div class="px-6 py-3 border-t border-gray-100 text-xs text-gray-400 flex gap-4 flex-wrap">
                            <span>Sync ID: <span class="font-mono">{{ $submission->sync_id }}</span></span>
                            <span>Versi: <span class="font-semibold">v{{ $submission->sync_version }}</span></span>
                            <span>Status sync: <span class="font-semibold">{{ ucfirst($submission->sync_status ?: 'unknown') }}</span></span>
                            @if($gradeStatus)
                                <span>Grade status: <span class="font-semibold">{{ ucfirst($gradeStatus) }}</span></span>
                            @endif
                        </div>

                    </div>

                @endforeach

            </div>

        @endif

    </div>
</div>

</x-app-layout>