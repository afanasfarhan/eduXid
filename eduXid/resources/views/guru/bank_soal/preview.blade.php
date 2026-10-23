<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Preview Soal</h2>
                <p class="mt-1 text-sm text-gray-500">Pratinjau tampilan soal seperti saat digunakan pada CBT atau naskah cetak.</p>
            </div>
            <div class="flex gap-2 no-print">
                <a href="{{ route('guru.bankSoal') }}"
                   class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    ← Kembali
                </a>
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    🖨️ Cetak Preview
                </button>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800 no-print">
                <strong>Mode Preview Siswa.</strong> Kunci jawaban tidak ditampilkan agar tampilan menyerupai CBT/naskah soal yang diterima siswa.
            </div>

            <article id="question-preview" class="bg-white shadow-sm border border-gray-200 rounded-2xl p-6 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-4 mb-6">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-indigo-600">
                            {{ $soal->subject->nama_mata_pelajaran ?? 'Mata Pelajaran' }}
                        </div>
                        <div class="mt-1 text-sm font-semibold text-gray-700">
                            {{ $soal->judul_materi ?: 'Tanpa judul materi' }}
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                            {{ ucwords(str_replace('_', ' ', $soal->jenis_soal)) }}
                        </span>
                        <span class="rounded-full bg-violet-50 px-3 py-1 font-semibold text-violet-700">
                            {{ $soal->level_kognitif }}
                        </span>
                        <span class="rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-700">
                            Bobot {{ $soal->bobot_nilai }}
                        </span>
                    </div>
                </div>

                @if(!empty($soal->indikator))
                    <div class="mb-5 rounded-lg bg-gray-50 px-4 py-3 text-xs text-gray-600 no-print">
                        <span class="font-semibold">Indikator:</span> {{ $soal->indikator }}
                    </div>
                @endif

                <div class="flex gap-3">
                    <div class="shrink-0 text-base font-bold text-gray-700">1.</div>
                    <div class="min-w-0 flex-1">
                        @if(!empty($soal->tka_stimulus))
                            <div class="mb-4 rounded-lg bg-indigo-50 border border-indigo-200 p-3 whitespace-pre-line text-sm">{{ $soal->tka_stimulus }}</div>
                        @endif
                        @if($instruction = \App\Support\QuestionTypeInstruction::forType($soal->jenis_soal, (bool) ($soal->tka_category ?? false)))
                            <p class="mb-3 text-sm font-semibold leading-relaxed text-slate-800">{{ $instruction }}</p>
                        @endif
                        <div class="eduxid-question prose max-w-none text-gray-900 leading-relaxed">
                            {!! \App\Support\QuestionHtmlSanitizer::clean($soal->pertanyaan) !!}
                        </div>

                        @if(!empty($soal->gambar_soal))
                            <div class="my-5">
                                <img src="{{ $soal->gambar_soal }}"
                                     alt="Gambar soal"
                                     class="max-h-[420px] max-w-full rounded-xl border border-gray-200 object-contain">
                            </div>
                        @endif

                        @if($soal->tka_category && $tkaGroupItems->isNotEmpty())
                            <table class="w-full text-sm border mt-5" style="border-collapse:collapse;">
                                <thead><tr class="bg-gray-100"><th class="text-left p-3">Pernyataan</th><th class="p-3">Benar</th><th class="p-3">Salah</th></tr></thead>
                                <tbody>
                                    @foreach($tkaGroupItems as $tkaItem)
                                        <tr class="border-t"><td class="p-3">{{ strip_tags($tkaItem->pertanyaan) }}</td>
                                            <td class="text-center p-3">◯</td><td class="text-center p-3">◯</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @elseif(in_array($soal->jenis_soal, ['pilihan_ganda', 'majemuk'], true))
                            <div class="mt-6 space-y-3">
                                @foreach($formattedOptions as $option)
                                    <div class="flex items-start gap-3 rounded-xl border border-gray-200 px-4 py-3">
                                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 border-gray-400 text-xs font-bold text-gray-600">
                                            {{ $option['key'] }}
                                        </span>
                                        <div class="eduxid-option min-w-0 flex-1 text-sm leading-relaxed text-gray-800">
                                            {!! \App\Support\QuestionHtmlSanitizer::clean($option['text']) !!}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @elseif($soal->jenis_soal === 'benar_salah')
                            <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-gray-200 px-4 py-3"><strong>A.</strong> Benar</div>
                                <div class="rounded-xl border border-gray-200 px-4 py-3"><strong>B.</strong> Salah</div>
                            </div>
                        @elseif($soal->jenis_soal === 'jawaban_singkat')
                            <div class="mt-8 border-b border-dashed border-gray-400 pb-2 text-sm text-gray-400">
                                Jawaban: ........................................................................................
                            </div>
                        @endif

                        @if(!empty($soal->video_url))
                            <div class="mt-5 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800 no-print">
                                🎬 Soal ini memiliki media video. Pada CBT, media tersedia melalui tautan yang disimpan pada soal.
                            </div>
                        @endif
                    </div>
                </div>
            </article>
        </div>
    </div>

    <link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
    <script src="{{ asset('katex/katex.min.js') }}"></script>
    <script src="{{ asset('katex/contrib/auto-render.min.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof renderMathInElement !== 'function') return;
            renderMathInElement(document.getElementById('question-preview'), {
                delimiters: [
                    {left: '$$', right: '$$', display: true},
                    {left: '\\[', right: '\\]', display: true},
                    {left: '$', right: '$', display: false},
                    {left: '\\(', right: '\\)', display: false}
                ],
                throwOnError: false
            });
        });
    </script>

    <style>
        .eduxid-question img, .eduxid-option img { max-width: 100%; height: auto; }
        @media print {
            @page { size: A4 portrait; margin: 16mm; }
            .no-print, nav { display: none !important; }
            body { background: white !important; }
            #question-preview { box-shadow: none !important; border: 0 !important; padding: 0 !important; }
        }
    </style>
</x-app-layout>
