<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Pilih Soal: {{ $exam->judul_ujian }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">Centang soal yang ingin dimasukkan ke paket ujian ini.</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-md rounded-xl border border-gray-100 overflow-hidden">
                <form action="{{ route('guru.cbt.soal.store', $exam->id) }}" method="POST" id="cbt-question-form">
                    @csrf

                    <div class="p-5 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-indigo-50">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-gray-700">Soal dalam paket</div>
                                <div class="mt-1 flex items-baseline gap-2">
                                    <span id="selected-question-count" class="text-3xl font-bold text-indigo-700">{{ count($selectedQuestions) }}</span>
                                    <span class="text-sm text-gray-500">dari {{ $bankSoal->count() }} soal bank</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button type="button" id="select-all-questions"
                                        class="px-3 py-2 text-xs font-semibold rounded-lg border border-indigo-200 bg-white text-indigo-700 hover:bg-indigo-50 transition">
                                    Pilih Semua
                                </button>
                                <button type="button" id="clear-all-questions"
                                        class="px-3 py-2 text-xs font-semibold rounded-lg border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 transition">
                                    Kosongkan
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="p-5">
                        <div class="space-y-3 max-h-[60vh] overflow-y-auto pr-2" id="question-list">
                            @foreach($bankSoal as $soal)
                                @php $isSelected = in_array($soal->id, $selectedQuestions); @endphp
                                <label class="question-item flex items-start gap-3 p-4 border rounded-xl cursor-pointer transition {{ $isSelected ? 'bg-blue-50 border-blue-400 ring-1 ring-blue-100' : 'border-gray-200 hover:bg-gray-50' }}">
                                    <input type="checkbox"
                                           name="questions[]"
                                           value="{{ $soal->id }}"
                                           data-tka-group="{{ $soal->tka_category ? ($soal->tka_group_code ?? '') : '' }}" class="question-checkbox mt-1 w-5 h-5 rounded text-blue-600 focus:ring-blue-500"
                                           {{ $isSelected ? 'checked' : '' }}>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap justify-between items-center gap-2 mb-2">
                                            <div class="flex flex-wrap gap-2">
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-slate-600">
                                                    {{ str_replace('_', ' ', $soal->jenis_soal) }}
                                                </span>
                                                @if(!empty($soal->tka_group_code))
                                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-1 text-[11px] text-indigo-700">Grup {{ $soal->tka_group_code }}{{ $soal->tka_category ? ' · kategori' : '' }}</span>
                                                @endif
                                                <span class="inline-flex items-center rounded-full bg-violet-50 px-2.5 py-1 text-[11px] font-bold text-violet-700">
                                                    {{ $soal->level_kognitif }}
                                                </span>
                                            </div>
                                            <span class="text-xs font-medium text-gray-400">Bobot: {{ $soal->bobot_nilai }}</span>
                                        </div>

                                        @if(!empty($soal->judul_materi))
                                            <div class="mb-2 text-xs font-semibold text-indigo-600">{{ $soal->judul_materi }}</div>
                                        @endif

                                        <div class="prose max-w-none text-gray-800 text-sm">{!! $soal->pertanyaan !!}</div>
                                    </div>
                                </label>
                            @endforeach

                            @if($bankSoal->isEmpty())
                                <div class="text-center text-gray-500 py-10">
                                    <div class="text-4xl mb-3">📝</div>
                                    <p>Bank Soal masih kosong. Silakan tambah soal terlebih dahulu di menu Bank Soal.</p>
                                </div>
                            @endif
                        </div>

                        <div class="mt-6 border-t border-gray-100 pt-5 flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
                            <div class="text-sm text-gray-500">
                                Dipilih: <strong id="selected-question-count-bottom" class="text-indigo-700">{{ count($selectedQuestions) }}</strong> soal.
                            </div>
                            <button type="submit"
                                    class="bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700 font-bold text-sm shadow-sm transition-colors">
                                Simpan Soal Terpilih
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const checkboxes = Array.from(document.querySelectorAll('.question-checkbox'));
            const topCount = document.getElementById('selected-question-count');
            const bottomCount = document.getElementById('selected-question-count-bottom');
            const btnAll = document.getElementById('select-all-questions');
            const btnClear = document.getElementById('clear-all-questions');

            function refreshQuestionCount() {
                const selected = checkboxes.filter(cb => cb.checked).length;
                if (topCount) topCount.textContent = String(selected);
                if (bottomCount) bottomCount.textContent = String(selected);

                checkboxes.forEach(cb => {
                    const item = cb.closest('.question-item');
                    if (!item) return;
                    item.classList.toggle('bg-blue-50', cb.checked);
                    item.classList.toggle('border-blue-400', cb.checked);
                    item.classList.toggle('ring-1', cb.checked);
                    item.classList.toggle('ring-blue-100', cb.checked);
                    item.classList.toggle('border-gray-200', !cb.checked);
                });
            }

            checkboxes.forEach(cb => cb.addEventListener('change', function () {
                const group = cb.dataset.tkaGroup;
                if (group) checkboxes.filter(other => other.dataset.tkaGroup === group).forEach(other => { other.checked = cb.checked; });
                refreshQuestionCount();
            }));

            btnAll?.addEventListener('click', function () {
                checkboxes.forEach(cb => { cb.checked = true; });
                refreshQuestionCount();
            });

            btnClear?.addEventListener('click', function () {
                checkboxes.forEach(cb => { cb.checked = false; });
                refreshQuestionCount();
            });

            refreshQuestionCount();
        });
    </script>
</x-app-layout>
