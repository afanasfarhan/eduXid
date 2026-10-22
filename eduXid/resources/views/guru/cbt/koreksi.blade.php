<x-app-layout>
<x-slot name="header">
    <div>
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Koreksi CBT</h2>
        <p class="mt-1 text-sm text-gray-500">Konfirmasi hasil objektif, koreksi soal manual, dan kirim nilai resmi ke Buku Nilai.</p>
    </div>
</x-slot>

@php
    $pendingTotal = $sessions->count();
    $pendingPwa = $sessions->filter(fn ($s) => trim((string) ($s->sync_id ?? '')) !== '')->count();
    $pendingLan = $pendingTotal - $pendingPwa;
    $examPendingCount = isset($exams) ? $exams->count() : 0;
@endphp

<div class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if(session('success'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">{{ session('success') }}</div>
        @endif
        @if(session('warning'))
            <div class="rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm font-medium text-yellow-800">{{ session('warning') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</div>
        @endif

        <section class="eduxid-page-card eduxid-page-card-red p-5 sm:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="max-w-2xl">
                    <div class="text-lg font-bold text-gray-800">Pusat Koreksi CBT</div>
                    <p class="mt-1 text-sm leading-6 text-gray-500">
                        Tinjau sesi yang sudah selesai, koreksi soal manual (esai/jawaban singkat) bila ada, lalu konfirmasi nilai resmi.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('guru.cbt') }}" class="eduxid-btn rounded-md border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 hover:bg-gray-50">← Manajemen Ujian</a>
                    <a href="{{ route('guru.ljk-auto.index') }}" class="eduxid-btn rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100">Koreksi LJK</a>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="eduxid-stat-card eduxid-stat-neutral eduxid-page-card eduxid-page-card-slate p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Menunggu Koreksi</p>
                <p class="mt-2 text-3xl font-black text-slate-900">{{ $pendingTotal }}</p>
            </div>
            <div class="eduxid-stat-card eduxid-stat-blue eduxid-page-card eduxid-page-card-blue bg-blue-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-blue-600">Sumber LAN</p>
                <p class="mt-2 text-3xl font-black text-blue-700">{{ $pendingLan }}</p>
            </div>
            <div class="eduxid-stat-card eduxid-stat-violet eduxid-page-card eduxid-page-card-violet bg-purple-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-purple-600">Sumber PWA</p>
                <p class="mt-2 text-3xl font-black text-purple-700">{{ $pendingPwa }}</p>
            </div>
            <div class="eduxid-stat-card eduxid-stat-amber eduxid-page-card eduxid-page-card-amber bg-amber-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-amber-600">Paket Terdampak</p>
                <p class="mt-2 text-3xl font-black text-amber-700">{{ $examPendingCount }}</p>
            </div>
        </section>

        <section class="eduxid-page-card eduxid-page-card-slate p-5 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="text-lg font-black text-slate-900">Alur Koreksi</h3>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">Soal objektif sudah memperoleh saran nilai dari sistem. Soal esai/jawaban singkat tetap wajib diperiksa guru. Nilai baru menjadi resmi setelah dikonfirmasi, lalu masuk Buku Nilai dan dikirim ke murid.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600">{{ $pendingTotal }} sesi antre</span>
            </div>
        </section>

        @if(isset($exams) && $exams->isNotEmpty())
            <section class="eduxid-page-card eduxid-page-card-indigo overflow-hidden bg-indigo-50">
                <div class="border-b border-indigo-200 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-indigo-600 font-black text-white">⚡</div>
                        <div>
                            <h3 class="font-black text-indigo-950">Koreksi Seluruh Murid Otomatis</h3>
                            <p class="mt-1 text-xs leading-5 text-indigo-800">Hanya tersedia untuk paket tanpa soal manual (esai/jawaban singkat). Sekali konfirmasi akan menghitung seluruh sesi pending, menyimpan nilai resmi, memperbarui Buku Nilai, dan mengirim hasil ke murid.</p>
                        </div>
                    </div>
                </div>
                <div class="divide-y divide-indigo-100 bg-white">
                    @foreach($exams as $exam)
                        @php
                            $pendingExam = $sessions->where('exam_id', $exam->id)->count();
                            $adaManual = $exam->questions->contains(function ($q) {
                                $jenis = strtolower(trim((string) ($q->jenis_soal ?? '')));
                                return str_contains($jenis, 'esai') || str_contains($jenis, 'singkat');
                            });
                        @endphp
                        <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <p class="font-black text-slate-900">{{ $exam->judul_ujian }}</p>
                                <div class="mt-1 flex flex-wrap gap-2 text-xs text-slate-500">
                                    <span>{{ $pendingExam }} murid menunggu koreksi</span>
                                    @if($adaManual)
                                        <span class="rounded-full bg-amber-100 px-2 py-0.5 font-bold text-amber-700">Ada soal manual</span>
                                    @else
                                        <span class="rounded-full bg-green-100 px-2 py-0.5 font-bold text-green-700">Objektif penuh</span>
                                    @endif
                                </div>
                            </div>
                            @if(!$adaManual && $pendingExam > 0)
                                <form method="POST" action="{{ route('guru.cbt.koreksi.autoGrade', $exam) }}" onsubmit="return confirm('Koreksi otomatis SEMUA murid pada ujian ini?\n\nNilai akan menjadi resmi, masuk Buku Nilai, dan hasil dikirim ke murid.')">
                                    @csrf
                                    <button type="submit" class="eduxid-btn rounded-md bg-red-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-red-800">Koreksi Semua Otomatis</button>
                                </form>
                            @else
                                <span class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">Koreksi manual diperlukan</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="eduxid-page-card eduxid-page-card-slate overflow-hidden">
            <div class="border-b border-slate-100 bg-slate-50 px-5 py-4 sm:px-6">
                <h3 class="text-lg font-black text-slate-900">Antrean Koreksi Per Murid</h3>
                <p class="mt-1 text-xs text-slate-500">Buka satu sesi untuk memeriksa jawaban dan memberi feedback.</p>
            </div>

            @forelse($sessions as $s)
                <div class="student-card flex flex-col gap-4 border-b border-slate-100 px-5 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between sm:px-6" data-eduxid-student-id="{{ $s->student->id ?? '' }}" data-eduxid-student-nisn="{{ $s->student->nisn ?? '' }}" data-eduxid-student-name="{{ $s->student->nama ?? '' }}">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-black text-slate-900">{{ $s->student->nama ?? 'Murid' }}</p>
                            <span class="font-mono text-xs text-slate-400">{{ $s->student->nisn ?? '-' }}</span>
                            <span class="rounded-full border px-2 py-0.5 text-[10px] font-bold {{ trim((string) ($s->sync_id ?? '')) !== '' ? 'border-purple-200 bg-purple-50 text-purple-700' : 'border-slate-200 bg-slate-50 text-slate-600' }}">
                                {{ trim((string) ($s->sync_id ?? '')) !== '' ? 'PWA' : 'LAN' }}
                            </span>
                        </div>
                        <p class="mt-1 text-sm font-semibold text-slate-700">{{ $s->exam->judul_ujian ?? '-' }}</p>
                        <p class="mt-1 text-xs text-slate-400">Dikumpulkan {{ optional($s->waktu_submit)->translatedFormat('d M Y H:i') }}</p>
                    </div>
                    <a href="{{ route('guru.cbt.koreksi.show', ['session' => $s->id]) }}" class="eduxid-btn shrink-0 rounded-xl bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Koreksi &amp; Beri Nilai →</a>
                </div>
            @empty
                <div class="px-6 py-14 text-center">
                    <div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-green-50 text-2xl">✓</div>
                    <div class="mt-3 font-black text-slate-800">Tidak ada antrean koreksi</div>
                    <div class="mt-1 text-sm text-slate-400">Semua jawaban yang selesai sudah memiliki nilai resmi.</div>
                </div>
            @endforelse
        </section>
    </div>
</div>
</x-app-layout>
