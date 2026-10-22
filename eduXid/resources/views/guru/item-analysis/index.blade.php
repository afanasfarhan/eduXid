<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Analisis Butir Soal</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $analysis['meta']['exam_title'] ?? 'Paket Ujian' }}{{ !empty($manualSession) ? ' · Kunci Manual Tanpa Paket' : '' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ !empty($manualSession) ? route('guru.ljk-auto.index') : route('guru.cbt') }}"
                   class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    ← {{ !empty($manualSession) ? 'Koreksi LJK' : 'Paket Ujian' }}
                </a>
                <a href="{{ !empty($manualSession) ? route('guru.ljk-auto.standalone.analysis.pdf', ['session' => $manualSession]) : route('guru.item-analysis.pdf', ['exam' => $exam->id, 'source' => $source]) }}"
                   class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">
                    📄 Download PDF
                </a>
            </div>
        </div>
    </x-slot>

    @php
        $summary = $analysis['summary'] ?? [];
        $meta = $analysis['meta'] ?? [];
        $items = $analysis['items'] ?? [];
        $statusCounts = $summary['status_counts'] ?? [];
        $fmt = static fn ($value, $digits = 2) => $value === null ? '-' : number_format((float) $value, $digits, ',', '.');
        $pct = static fn ($value, $digits = 1) => $value === null ? '-' : number_format(((float) $value) * 100, $digits, ',', '.') . '%';
        $statusClass = static function ($status) {
            return match ($status) {
                'Pertahankan' => 'bg-green-100 text-green-700 border-green-200',
                'Perbaiki' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                'Bermasalah' => 'bg-red-100 text-red-700 border-red-200',
                'Tidak Dianalisis' => 'bg-gray-100 text-gray-600 border-gray-200',
                default => 'bg-orange-100 text-orange-800 border-orange-200',
            };
        };
    @endphp

    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    {{ session('success') }}
                </div>
            @endif

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h3 class="font-bold text-gray-900">Sumber Jawaban</h3>
                            <p class="mt-1 text-xs text-gray-500">{{ !empty($manualSession) ? 'Analisis hanya menggunakan hasil LJK manual berstatus Berhasil dari sesi ini, termasuk hasil PWA yang telah diterima laptop. Jawaban ganda satu siswa dihitung satu kali.' : 'Gunakan Gabungan untuk menganalisis peserta CBT dan LJK dalam satu paket tanpa menghitung siswa yang sama dua kali.' }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if(!empty($manualSession))
                                <span class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">LJK Manual</span>
                            @else
                            @foreach([
                                'combined' => 'Gabungan CBT + LJK',
                                'cbt' => 'CBT',
                                'ljk' => 'LJK',
                            ] as $key => $label)
                                <a href="{{ route('guru.item-analysis.index', ['exam' => $exam->id, 'source' => $key]) }}"
                                   class="rounded-lg border px-3 py-2 text-sm font-semibold transition {{ $source === $key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}">
                                    {{ $label }}
                                </a>
                            @endforeach
                            @endif
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Nama Guru</div>
                        <div class="mt-1 font-bold text-gray-900">{{ $meta['teacher_name'] ?? '-' }}</div>
                        @if(!empty($meta['teacher_nip']))
                            <div class="text-xs text-gray-500">NIP/NIK {{ $meta['teacher_nip'] }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Mata Pelajaran</div>
                        <div class="mt-1 font-bold text-gray-900">{{ $meta['subject_name'] ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Sekolah</div>
                        <div class="mt-1 font-bold text-gray-900">{{ $meta['school_name'] ?? '-' }}</div>
                        @if(!empty($meta['school_npsn']))
                            <div class="text-xs text-gray-500">NPSN {{ $meta['school_npsn'] }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Tingkat Kelas</div>
                        <div class="mt-1 font-bold text-gray-900">{{ $meta['grade_level'] ?? '-' }}</div>
                        <div class="text-xs text-gray-500">{{ $meta['academic_year'] ?? '-' }} · {{ $meta['semester'] ?? '-' }}</div>
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                @php
                    $cards = [
                        ['Peserta', (int)($summary['participant_count'] ?? 0), 'CBT ' . (int)($summary['cbt_count'] ?? 0) . ' · LJK ' . (int)($summary['ljk_count'] ?? 0)],
                        ['Jumlah Soal', (int)($summary['question_count'] ?? 0), 'Butir dianalisis'],
                        ['Rata-rata', $fmt($summary['mean_score'] ?? null, 1), 'Median ' . $fmt($summary['median_score'] ?? null, 1)],
                        ['Rentang Nilai', $fmt($summary['min_score'] ?? null, 0) . '–' . $fmt($summary['max_score'] ?? null, 0), 'SD ' . $fmt($summary['stddev_score'] ?? null, 2)],
                        ['Reliabilitas', $fmt($summary['reliability_alpha'] ?? null, 3), $summary['reliability_label'] ?? '-'],
                        ['Duplikat Dicegah', (int)($summary['deduped_count'] ?? 0), 'Peserta tidak dihitung ganda'],
                    ];
                @endphp
                @foreach($cards as [$label, $value, $sub])
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</div>
                        <div class="mt-2 text-2xl font-black text-gray-900">{{ $value }}</div>
                        <div class="mt-1 text-xs text-gray-500">{{ $sub }}</div>
                    </div>
                @endforeach
            </section>

            @if(!empty($manualSession))
                <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">Kunci manual tidak menyimpan teks soal, materi, indikator, atau level kognitif. Statistik kesukaran, daya pembeda, korelasi dan distraktor tetap dihitung dari jawaban murid; rincian konten soal tidak tersedia.</div>
            @endif
            @if(!empty($summary['sample_note']))
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    ⚠ {{ $summary['sample_note'] }}
                </div>
            @endif

            <section class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="font-bold text-gray-900">Keputusan Butir</h3>
                        <span class="text-xs text-gray-400">CTT</span>
                    </div>
                    <div class="space-y-2">
                        @foreach(['Pertahankan','Perbaiki','Tinjau','Bermasalah','Tidak Dianalisis'] as $status)
                            <div class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2">
                                <span class="text-sm text-gray-600">{{ $status }}</span>
                                <span class="font-bold text-gray-900">{{ (int)($statusCounts[$status] ?? 0) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-4 font-bold text-gray-900">Analisis Tingkat Kognitif</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-400">
                                <tr><th class="pb-2">Level</th><th class="pb-2 text-center">Soal</th><th class="pb-2 text-right">Rata-rata Benar</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse($analysis['cognitive'] ?? [] as $row)
                                    <tr>
                                        <td class="py-2 font-semibold text-gray-700">{{ $row['label'] }}</td>
                                        <td class="py-2 text-center">{{ $row['items'] }}</td>
                                        <td class="py-2 text-right font-semibold">{{ $fmt($row['mean_correct_pct'] ?? null, 1) }}%</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="py-4 text-center text-gray-400">Belum ada data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="mb-4 font-bold text-gray-900">Snapshot Analisis</h3>
                    @if(!empty($manualSession))
                    <p class="text-sm leading-6 text-gray-500">Analisis sesi manual dihitung langsung dari hasil koreksi tersimpan. Fitur Snapshot Paket Ujian tidak digunakan karena sesi ini tidak memiliki <code>exam_id</code>. Anda dapat mengunduh PDF untuk arsip.</p>
                    @else
                    <p class="text-sm leading-6 text-gray-500">Simpan kondisi analisis saat ini untuk histori Paket Ujian. Snapshot tidak mengubah jawaban atau nilai.</p>
                    @if($latestSnapshot)
                        <div class="mt-3 rounded-lg bg-indigo-50 px-3 py-2 text-xs text-indigo-700">
                            Terakhir disimpan: {{ \Carbon\Carbon::parse($latestSnapshot->created_at)->translatedFormat('d M Y H:i') }}<br>
                            Peserta: {{ $latestSnapshot->participant_count }} · α: {{ $fmt($latestSnapshot->reliability_alpha, 3) }}
                        </div>
                    @endif
                    <form method="POST" action="{{ route('guru.item-analysis.snapshot', ['exam' => $exam->id]) }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="source" value="{{ $source }}">
                        <button type="submit"
                                class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">
                            💾 Simpan Snapshot
                        </button>
                    </form>
                    @endif
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h3 class="font-bold text-gray-900">Analisis Indikator</h3>
                        <p class="mt-1 text-xs text-gray-500">Rata-rata persentase peserta yang menjawab benar pada butir dalam indikator yang sama.</p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Indikator</th>
                                <th class="px-3 py-2 text-center">Jml Soal</th>
                                <th class="px-3 py-2 text-right">Rata-rata Benar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($analysis['indicators'] ?? [] as $row)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-700">{{ $row['label'] }}</td>
                                    <td class="px-3 py-2 text-center">{{ $row['items'] }}</td>
                                    <td class="px-3 py-2 text-right font-bold">{{ $fmt($row['mean_correct_pct'] ?? null, 1) }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-5 text-center text-gray-400">Belum ada indikator.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-5 py-4">
                    <h3 class="font-bold text-gray-900">Hasil Analisis Butir Soal Lengkap</h3>
                    <p class="mt-1 text-xs text-gray-500">Klik “Detail” untuk melihat soal dan fungsi setiap pilihan jawaban/distraktor.</p>
                </div>

                @if(empty($items))
                    <div class="px-6 py-12 text-center text-gray-500">
                        <div class="text-4xl">📊</div>
                        <div class="mt-3 font-bold text-gray-700">Belum ada butir yang dapat dianalisis.</div>
                        <p class="mt-1 text-sm">Pastikan Paket Ujian memiliki soal dan jawaban final.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-[1500px] w-full text-sm">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-3 text-center">No</th>
                                    <th class="px-3 py-3 text-left">Indikator</th>
                                    <th class="px-3 py-3 text-center">Level</th>
                                    <th class="px-3 py-3 text-center">Kunci</th>
                                    <th class="px-3 py-3 text-center">Benar</th>
                                    <th class="px-3 py-3 text-center">Kesukaran</th>
                                    <th class="px-3 py-3 text-center">Daya Pembeda</th>
                                    <th class="px-3 py-3 text-center">r<sub>pb</sub></th>
                                    <th class="px-3 py-3 text-center">Distraktor</th>
                                    <th class="px-3 py-3 text-center">α jika dihapus</th>
                                    <th class="px-3 py-3 text-center">Status</th>
                                    <th class="px-3 py-3 text-left">Rekomendasi</th>
                                    <th class="px-3 py-3 text-center">Detail</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($items as $item)
                                    @php
                                        $badOptions = collect($item['options'] ?? [])->filter(fn($o) => !($o['is_key'] ?? false) && in_array($o['status'] ?? '', ['Tidak berfungsi','Lemah','Mencurigakan']))->count();
                                    @endphp
                                    <tr class="align-top hover:bg-gray-50">
                                        <td class="px-3 py-3 text-center font-bold">{{ $item['order'] }}</td>
                                        <td class="max-w-xs px-3 py-3 text-gray-700">{{ $item['indicator'] ?: '-' }}</td>
                                        <td class="px-3 py-3 text-center font-semibold">{{ $item['cognitive_level'] ?: '-' }}</td>
                                        <td class="px-3 py-3 text-center font-mono font-bold">{{ $item['answer_key'] ?: '-' }}</td>
                                        <td class="px-3 py-3 text-center">{{ $item['correct_count'] }}/{{ $item['participant_count'] }}</td>
                                        <td class="px-3 py-3 text-center">
                                            <div class="font-bold">{{ $fmt($item['difficulty_index'], 2) }}</div>
                                            <div class="text-xs text-gray-500">{{ $item['difficulty_label'] }}</div>
                                        </td>
                                        <td class="px-3 py-3 text-center">
                                            <div class="font-bold {{ ($item['discrimination_index'] ?? 0) < 0 ? 'text-red-600' : '' }}">{{ $fmt($item['discrimination_index'], 2) }}</div>
                                            <div class="text-xs text-gray-500">{{ $item['discrimination_label'] }}</div>
                                        </td>
                                        <td class="px-3 py-3 text-center">
                                            <div class="font-bold {{ ($item['point_biserial'] ?? 0) < 0 ? 'text-red-600' : '' }}">{{ $fmt($item['point_biserial'], 2) }}</div>
                                            <div class="text-xs text-gray-500">{{ $item['point_biserial_label'] }}</div>
                                        </td>
                                        <td class="px-3 py-3 text-center">
                                            @if(empty($item['options']))
                                                <span class="text-gray-400">-</span>
                                            @elseif($badOptions > 0)
                                                <span class="rounded-full bg-yellow-100 px-2 py-1 text-xs font-bold text-yellow-800">{{ $badOptions }} perlu ditinjau</span>
                                            @else
                                                <span class="rounded-full bg-green-100 px-2 py-1 text-xs font-bold text-green-700">Baik</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-center font-semibold">{{ $fmt($item['alpha_if_deleted'], 3) }}</td>
                                        <td class="px-3 py-3 text-center">
                                            <span class="inline-flex rounded-full border px-2 py-1 text-xs font-bold {{ $statusClass($item['status']) }}">{{ $item['status'] }}</span>
                                        </td>
                                        <td class="max-w-sm px-3 py-3 text-xs leading-5 text-gray-600">{{ $item['recommendation'] }}</td>
                                        <td class="px-3 py-3 text-center">
                                            <button type="button"
                                                    onclick="document.getElementById('detail-item-{{ $item['question_id'] }}').classList.toggle('hidden')"
                                                    class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100">
                                                Detail
                                            </button>
                                        </td>
                                    </tr>
                                    <tr id="detail-item-{{ $item['question_id'] }}" class="hidden bg-slate-50">
                                        <td colspan="13" class="px-5 py-5">
                                            <div class="grid gap-5 lg:grid-cols-2">
                                                <div class="space-y-3">
                                                    <div>
                                                        <div class="text-xs font-bold uppercase tracking-wide text-gray-400">Butir Soal</div>
                                                        <div class="mt-2 rounded-xl border border-gray-200 bg-white p-4 text-sm leading-6 text-gray-700">{{ $item['stem'] ?: '-' }}</div>
                                                    </div>
                                                    <div class="grid grid-cols-2 gap-3 text-sm">
                                                        <div class="rounded-xl border border-gray-200 bg-white p-3"><span class="text-gray-400">Jenis:</span> <b>{{ $item['type'] ?: '-' }}</b></div>
                                                        <div class="rounded-xl border border-gray-200 bg-white p-3"><span class="text-gray-400">Materi:</span> <b>{{ $item['material'] ?: '-' }}</b></div>
                                                        <div class="rounded-xl border border-gray-200 bg-white p-3"><span class="text-gray-400">Kosong:</span> <b>{{ $item['blank_count'] }}</b></div>
                                                        <div class="rounded-xl border border-gray-200 bg-white p-3"><span class="text-gray-400">N valid:</span> <b>{{ $item['participant_count'] }}</b></div>
                                                    </div>
                                                </div>

                                                <div>
                                                    <div class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Analisis Pilihan / Distraktor</div>
                                                    @if(empty($item['options']))
                                                        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-500">Jenis soal ini tidak memiliki distraktor pilihan ganda.</div>
                                                    @else
                                                        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white">
                                                            <table class="w-full text-xs">
                                                                <thead class="bg-gray-50 text-gray-500">
                                                                    <tr>
                                                                        <th class="px-2 py-2">Opsi</th>
                                                                        <th class="px-2 py-2 text-right">Dipilih</th>
                                                                        <th class="px-2 py-2 text-right">%</th>
                                                                        <th class="px-2 py-2 text-center">Atas</th>
                                                                        <th class="px-2 py-2 text-center">Bawah</th>
                                                                        <th class="px-2 py-2 text-center">Korelasi</th>
                                                                        <th class="px-2 py-2 text-center">Status</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody class="divide-y divide-gray-100">
                                                                    @foreach($item['options'] as $opt)
                                                                        <tr class="{{ !empty($opt['is_key']) ? 'bg-green-50' : '' }}">
                                                                            <td class="px-2 py-2 font-bold">{{ $opt['key'] }}{{ !empty($opt['is_key']) ? ' ✓' : '' }}</td>
                                                                            <td class="px-2 py-2 text-right">{{ $opt['count'] }}</td>
                                                                            <td class="px-2 py-2 text-right">{{ $pct($opt['share'] ?? 0) }}</td>
                                                                            <td class="px-2 py-2 text-center">{{ $opt['upper_count'] }}</td>
                                                                            <td class="px-2 py-2 text-center">{{ $opt['lower_count'] }}</td>
                                                                            <td class="px-2 py-2 text-center">{{ $fmt($opt['correlation'] ?? null, 2) }}</td>
                                                                            <td class="px-2 py-2 text-center font-semibold">{{ $opt['status'] }}</td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="rounded-2xl border border-blue-200 bg-blue-50 p-5 text-sm leading-6 text-blue-900">
                <div class="font-bold">Catatan Interpretasi</div>
                <p class="mt-1">Kategori kesukaran, daya pembeda, korelasi item–total, fungsi distraktor, dan reliabilitas pada halaman ini adalah pedoman psikometri Classical Test Theory (CTT) yang dapat dikonfigurasi. Keputusan akhir tetap mempertimbangkan validitas isi, indikator, tingkat kognitif, tujuan penilaian, dan ukuran sampel.</p>
            </section>
        </div>
    </div>
</x-app-layout>
