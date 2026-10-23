<x-app-layout>
    <x-slot name="header"><div class="flex flex-col gap-2">
        <h2 class="text-xl font-semibold text-gray-800">Pratinjau Impor Soal Word</h2>
        <p class="text-sm text-gray-600">Periksa soal, opsi, gambar, tabel, dan kunci sebelum disimpan ke Bank Soal. Belum ada soal yang dimasukkan ke database.</p>
    </div></x-slot>
    <div class="py-8 max-w-5xl mx-auto px-3 sm:px-6">
        <div class="bg-white rounded-xl border p-4 mb-5 space-y-2">
            <div class="font-semibold">{{ count($draft['parsed']['questions']) }} calon soal · {{ $draft['parsed']['metadata']['mapel'] ?? 'Mapel mengikuti penugasan' }} · Kelas {{ $draft['tingkat_kelas'] }}</div>
            <p class="text-sm text-gray-600">AI Lokal: {{ str_replace('_', ' ', $draft['parsed']['ai_status'] ?? 'nonaktif') }}. Hasil AI hanya berupa catatan pemeriksaan; tidak mengganti teks asli maupun menentukan kunci jawaban.</p>
            <p class="text-xs text-amber-700">Kunci tidak tersedia pada dokumen? Isikan secara manual atau hilangkan centang soal tersebut. Pratinjau otomatis kedaluwarsa dalam satu jam.</p>
        </div>
        @if($errorMessage)
            <div class="mb-5 bg-red-50 text-red-800 border border-red-200 rounded-lg p-4">{{ $errorMessage }}</div>
        @endif
        <form action="{{ route('guru.bankSoal.import') }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="word_mode" value="commit">
            <input type="hidden" name="draft_token" value="{{ $token }}">
            @foreach($draft['parsed']['questions'] as $index => $item)
                @php
                    $chosen = array_key_exists('selected', $prefill) ? in_array($index, array_map('intval', (array) $prefill['selected']), true) : count($item['options']) === 5;
                    $type = $prefill['jenis_soal'][$index] ?? 'pilihan_ganda';
                    $value = $prefill['pertanyaan'][$index] ?? $item['text'];
                @endphp
                <details class="bg-white border rounded-xl shadow-sm p-4" @if($index === 0 || !empty($item['warnings'])) open @endif>
                    <summary class="cursor-pointer font-semibold text-gray-800">Soal Word No. {{ $item['number'] }} · {{ count($item['options']) }}/5 opsi
                        @if(!empty($item['warnings'])) <span class="text-amber-700 text-xs">· Perlu ditinjau</span>@endif
                    </summary>
                    <div class="mt-4 space-y-3">
                        <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="selected[]" value="{{ $index }}" @checked($chosen)> Simpan soal ini</label>
                        @if(!empty($item['warnings']))
                            <div class="rounded-md bg-amber-50 p-3 text-xs text-amber-900">
                                @foreach($item['warnings'] as $warn)<p>{{ $warn }}</p>@endforeach
                            </div>
                        @endif
                        <div class="text-sm text-gray-600 font-medium">Pratinjau soal</div>
                        <div data-eduxid-word-preview class="max-w-full overflow-x-auto p-3 rounded-md border bg-gray-50 text-sm [&_table]:border-collapse [&_td]:border [&_td]:border-gray-400 [&_td]:p-2">
                            {!! \App\Support\QuestionHtmlSanitizer::clean($item['text']) !!}
                        </div>
                        @if(!empty($item['image']))
                            <div><img src="{{ $item['image'] }}" alt="Gambar dari Word untuk soal {{ $item['number'] }}" class="max-h-56 max-w-full rounded border"></div>
                        @endif
                        <label class="block text-sm font-medium">Teks soal (HTML tabel dapat disunting)
                            <textarea name="pertanyaan[{{ $index }}]" rows="5" class="mt-1 w-full rounded-lg border-gray-300">{{ $value }}</textarea>
                        </label>
                        <label class="block text-sm font-medium">Jenis Soal
                            <select name="jenis_soal[{{ $index }}]" class="mt-1 rounded-md border-gray-300 w-full">
                                <option value="pilihan_ganda" @selected($type === 'pilihan_ganda')>Pilihan Ganda A–E</option>
                                <option value="majemuk" @selected($type === 'majemuk')>Pilihan Majemuk A–E</option>
                                <option value="benar_salah" @selected($type === 'benar_salah')>Benar/Salah (A=Benar, B=Salah)</option>
                            </select>
                        </label>
                        <div class="grid grid-cols-1 gap-2">
                            @foreach(['A','B','C','D','E'] as $letter)
                                <label class="block text-sm font-medium">Opsi {{ $letter }}
                                    <textarea name="opsi[{{ $index }}][{{ $letter }}]" rows="2" class="mt-1 w-full rounded-md border-gray-300">{{ $prefill['opsi'][$index][$letter] ?? ($item['options'][$letter] ?? '') }}</textarea>
                                </label>
                            @endforeach
                        </div>
                        <label class="block text-sm font-medium">Kunci jawaban (diisi/diperiksa guru)
                            <input type="text" name="kunci[{{ $index }}]" value="{{ $prefill['kunci'][$index] ?? '' }}" placeholder="A, atau A,C untuk majemuk; A/B untuk benar-salah" maxlength="20" class="mt-1 w-full rounded-md border-gray-300">
                        </label>
                    </div>
                </details>
            @endforeach
            <div class="sticky bottom-0 z-10 bg-white border shadow-lg rounded-xl p-3 flex gap-3 flex-wrap justify-end items-center">
                <span class="text-xs text-gray-600 mr-auto">Hanya soal yang dicentang dan mempunyai kunci valid yang akan disimpan.</span>
                <a href="{{ route('guru.bankSoal') }}" class="px-4 py-2 border rounded-lg text-gray-600">Batal</a>
                <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg">Simpan Soal Terpilih</button>
            </div>
        </form>
    </div>

    <!-- eduXid V21R181: KaTeX lokal tetap renderer; MathLive jika vendor lokal tersedia. -->
    <link rel="stylesheet" href="{{ asset('katex/katex.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/eduxid-math/eduxid-math.css') }}?v=V21R181">
    <script defer src="{{ asset('katex/katex.min.js') }}"></script>
    <script defer src="{{ asset('katex/contrib/auto-render.min.js') }}"></script>
    <script defer src="{{ asset('vendor/eduxid-math/eduxid-math.js') }}?v=V21R181"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof renderMathInElement !== 'function') return;
      document.querySelectorAll('[data-eduxid-word-preview]').forEach(function (el) {
        renderMathInElement(el,{delimiters:[
          {left:'$$',right:'$$',display:true},
          {left:'\\[',right:'\\]',display:true},
          {left:'\\(',right:'\\)',display:false},
          {left:'$',right:'$',display:false}
        ],throwOnError:false,trust:false});
      });
    });
    </script>
</x-app-layout>
