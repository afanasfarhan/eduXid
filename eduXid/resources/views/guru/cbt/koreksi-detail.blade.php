<x-app-layout>
<x-slot name="header">
    <div>
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Koreksi CBT · {{ $student->nama ?? 'Murid' }}</h2>
        <p class="mt-1 text-sm text-gray-500">Periksa jawaban, koreksi saran sistem bila perlu, lalu konfirmasi nilai resmi.</p>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
        @if(session('success'))<div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">{{ session('success') }}</div>@endif
        @if(session('warning'))<div class="rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm font-medium text-yellow-800">{{ session('warning') }}</div>@endif
        @if(session('error'))<div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</div>@endif

        <section class="eduxid-page-card eduxid-page-card-red p-5 sm:p-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <div class="text-lg font-bold text-gray-800">Detail Koreksi</div>
                    <p class="mt-1 text-sm text-gray-500">Pastikan jawaban dan nilai sudah benar sebelum dikonfirmasi sebagai nilai resmi.</p>
                </div>
                <a href="{{ route('guru.cbt.koreksi') }}" class="eduxid-btn rounded-md border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 hover:bg-gray-50">← Antrean Koreksi</a>
            </div>
        </section>

        <section class="eduxid-page-card eduxid-page-card-slate p-5 sm:p-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Murid</p>
                    <p class="mt-1 font-black text-slate-900">{{ $student->nama ?? '-' }}</p>
                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $student->nisn ?? '-' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Paket Ujian</p>
                    <p class="mt-1 font-black text-slate-900">{{ $exam->judul_ujian ?? '-' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $exam->durasi_menit ?? '-' }} menit</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Dikumpulkan</p>
                    <p class="mt-1 font-black text-slate-900">{{ optional($session->waktu_submit)->translatedFormat('d M Y') ?? '-' }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ optional($session->waktu_submit)->format('H:i') ?? '-' }}</p>
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('guru.cbt.koreksi.confirm', $session->id) }}" class="space-y-5">
            @csrf

            <section class="space-y-4">
                @foreach($rows as $i => $row)
                    @php $q = $row->question; $qid = $q->id; @endphp
                    <article class="eduxid-page-card eduxid-page-card-slate overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 bg-slate-50 px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="rounded-full bg-slate-900 px-2.5 py-1 font-black text-white">Soal {{ $i + 1 }}</span>
                                <span class="font-semibold text-slate-500">{{ $q->jenis_soal }}</span>
                                <span class="text-slate-400">Bobot {{ $q->bobot_nilai ?? 1 }}</span>
                            </div>
                            @if($row->suggestion === null)
                                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-700">Manual · wajib dinilai guru</span>
                            @else
                                <span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">Saran sistem: {{ $row->suggestion ? 'Benar' : 'Salah' }}</span>
                            @endif
                        </div>
                        <div class="p-5 sm:p-6">
                            <div class="whitespace-pre-line text-sm leading-6 text-slate-800">{{ $q->pertanyaan }}</div>
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Jawaban Murid</p>
                                <p class="mt-2 whitespace-pre-line text-sm font-semibold text-slate-700">{{ trim((string) $row->jawaban) !== '' ? $row->jawaban : '(kosong)' }}</p>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2.5 text-sm font-bold text-green-800">
                                    <input type="checkbox" name="benar[{{ $qid }}]" value="1" class="cb-benar rounded border-green-300 text-green-600 focus:ring-green-500" data-qid="{{ $qid }}" @checked($row->suggestion === true)>
                                    Benar
                                </label>
                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-800">
                                    <input type="checkbox" name="salah[{{ $qid }}]" value="1" class="cb-salah rounded border-red-300 text-red-600 focus:ring-red-500" data-qid="{{ $qid }}" @checked($row->suggestion === false)>
                                    Salah
                                </label>
                            </div>
                        </div>
                    </article>
                @endforeach
            </section>

            <section class="eduxid-page-card eduxid-page-card-slate p-5 sm:p-6">
                <label class="block text-sm font-black text-slate-800" for="cbt-feedback">Feedback untuk murid <span class="font-normal text-slate-400">(opsional)</span></label>
                <p class="mt-1 text-xs text-slate-500">Feedback ini akan ikut diterima murid bersama hasil koreksi.</p>
                <textarea id="cbt-feedback" name="feedback" rows="4" class="mt-3 w-full rounded-xl border-slate-300 p-3 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Tulis feedback yang akan diterima murid di HP..."></textarea>
            </section>

            <section class="sticky bottom-4 eduxid-page-card eduxid-page-card-emerald bg-white/95 p-4 shadow-lg backdrop-blur">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-5 text-slate-500">Konfirmasi akan menjadikan nilai resmi, menulis Buku Nilai, dan mengirim hasil ke murid.</p>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('guru.cbt.koreksi') }}" class="eduxid-btn rounded-md border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Batal</a>
                        <button type="submit" class="eduxid-btn rounded-md bg-green-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-700" onclick="return confirm('Konfirmasi nilai resmi?\n\nNilai akan masuk Buku Nilai dan hasil dikirim ke murid.')">Konfirmasi Nilai</button>
                    </div>
                </div>
            </section>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.cb-benar').forEach(function (cb) {
    cb.addEventListener('change', function () {
        if (this.checked) {
            var s = document.querySelector('.cb-salah[data-qid="' + this.dataset.qid + '"]');
            if (s) s.checked = false;
        }
    });
});
document.querySelectorAll('.cb-salah').forEach(function (cb) {
    cb.addEventListener('change', function () {
        if (this.checked) {
            var b = document.querySelector('.cb-benar[data-qid="' + this.dataset.qid + '"]');
            if (b) b.checked = false;
        }
    });
});
</script>
</x-app-layout>
