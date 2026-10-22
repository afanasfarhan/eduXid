<x-app-layout>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Manajemen Ujian</h2>
            <p class="mt-1 text-sm text-gray-500">Kelola paket ujian, distribusi CBT, koreksi, LJK, dan monitoring peserta.</p>
        </div>
    </x-slot>

    @php
        $totalPaket = $exams->count();
        $paketAktif = $exams->where('status', 'aktif')->count();
        $pendingKoreksiCount = isset($koreksiPending) ? $koreksiPending->count() : 0;
        $sedangMengerjakanCount = $sessions->where('status', 'sedang_mengerjakan')->count();
    @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">{{ session('success') }}</div>
            @endif
            @if (session('warning'))
                <div class="rounded-xl border border-yellow-200 bg-yellow-50 px-4 py-3 text-sm font-medium text-yellow-800">{{ session('warning') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</div>
            @endif

            <section class="eduxid-page-card eduxid-page-card-indigo p-5 sm:p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div class="max-w-2xl">
                        <div class="text-lg font-bold text-gray-800">Kelola Ujian</div>
                        <p class="mt-1 text-sm leading-6 text-gray-500">
                            Buat paket baru, koreksi hasil CBT, atau proses LJK dari satu alur kerja yang terhubung ke Buku Nilai.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('guru.cbt.create') }}" class="eduxid-btn rounded-md bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-700">+ Buat Paket Ujian</a>
                        <a href="{{ route('guru.cbt.koreksi') }}" class="eduxid-btn rounded-md border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-700 hover:bg-red-100">Koreksi CBT</a>
                        <a href="{{ route('guru.ljk-auto.index') }}" class="eduxid-btn rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 hover:bg-emerald-100">Koreksi LJK</a>
                    </div>
                </div>
            </section>

            <section class="eduxid-page-card eduxid-page-card-indigo overflow-hidden">
                <div class="grid gap-4 bg-indigo-50 px-5 py-5 lg:grid-cols-[1fr_auto] lg:items-center sm:px-6">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-indigo-600 px-3 py-1 text-xs font-bold text-white">SERVER CBT LAN</span>
                            <span class="font-mono text-sm font-black text-indigo-900">{{ get_local_ip() }}:8000/cbt</span>
                        </div>
                        <p class="mt-2 text-sm text-indigo-700">Gunakan alamat ini pada perangkat murid yang terhubung ke WiFi sekolah yang sama.</p>
                    </div>
                    <div class="rounded-xl border border-indigo-200 bg-white px-4 py-3 text-xs leading-5 text-slate-600">
                        Paket dapat berjalan melalui LAN. Sinkronisasi Cloud digunakan untuk distribusi ke PWA murid bila aktivitas LMS tersedia.
                    </div>
                </div>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="eduxid-stat-card eduxid-stat-neutral eduxid-page-card eduxid-page-card-slate p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-400">Paket Ujian</p>
                    <p class="mt-2 text-3xl font-black text-slate-900">{{ $totalPaket }}</p>
                </div>
                <div class="eduxid-stat-card eduxid-stat-emerald eduxid-page-card eduxid-page-card-emerald bg-emerald-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-600">Paket Aktif</p>
                    <p class="mt-2 text-3xl font-black text-emerald-700">{{ $paketAktif }}</p>
                </div>
                <div class="eduxid-stat-card eduxid-stat-blue eduxid-page-card eduxid-page-card-blue bg-blue-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-blue-600">Sedang Mengerjakan</p>
                    <p class="mt-2 text-3xl font-black text-blue-700">{{ $sedangMengerjakanCount }}</p>
                </div>
                <div class="eduxid-stat-card eduxid-stat-amber eduxid-page-card eduxid-page-card-amber bg-amber-50 p-4">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-600">Menunggu Koreksi</p>
                    <p class="mt-2 text-3xl font-black text-amber-700">{{ $pendingKoreksiCount }}</p>
                </div>
            </section>

            @if($pendingKoreksiCount > 0)
                <section class="eduxid-page-card eduxid-page-card-amber overflow-hidden bg-amber-50">
                    <div class="flex flex-col gap-3 border-b border-amber-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="font-black text-amber-900">Menunggu Koreksi ({{ $pendingKoreksiCount }})</h3>
                            <p class="mt-1 text-xs leading-5 text-amber-800">Sesi LAN dan PWA yang sudah selesai tetapi belum memiliki nilai resmi.</p>
                        </div>
                        <a href="{{ route('guru.cbt.koreksi') }}" class="eduxid-btn rounded-md bg-amber-500 px-4 py-2 text-sm font-bold text-white hover:bg-amber-600">Buka Semua Koreksi</a>
                    </div>
                    <div class="divide-y divide-amber-100 bg-white">
                        @foreach($koreksiPending as $k)
                            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <div class="font-bold text-slate-900">{{ $k->nama_siswa }} <span class="text-xs font-normal text-slate-400">({{ $k->nisn }})</span></div>
                                    <div class="mt-1 text-sm text-slate-600">{{ $k->judul_ujian }}</div>
                                    <div class="mt-1 text-xs text-slate-400">Dikumpulkan {{ \Carbon\Carbon::parse($k->waktu_submit)->translatedFormat('d M Y H:i') }}</div>
                                </div>
                                <a href="{{ route('guru.cbt.koreksi.show', ['session' => $k->session_id]) }}" class="eduxid-btn rounded-md bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">Koreksi &amp; Beri Nilai</a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="eduxid-page-card eduxid-page-card-slate overflow-hidden">
                <div class="flex flex-col gap-2 border-b border-slate-100 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-black text-slate-900">Daftar Paket Ujian</h3>
                        <p class="mt-1 text-xs text-slate-500">Kelola soal, distribusi, status ujian, analisis, dan penghapusan paket.</p>
                    </div>
                    <a href="{{ route('guru.cbt.create') }}" class="eduxid-btn rounded-md bg-blue-600 px-4 py-2 text-sm font-bold text-white hover:bg-blue-700">+ Paket Baru</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white text-xs uppercase tracking-wide text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="px-4 py-3 text-left">Paket</th>
                                <th class="px-4 py-3 text-center">Tingkat</th>
                                <th class="px-4 py-3 text-center">Durasi</th>
                                <th class="px-4 py-3 text-center">Soal</th>
                                <th class="px-4 py-3 text-center">Status</th>
                                <th class="px-4 py-3 text-center">Sinkron</th>
                                <th class="px-4 py-3 text-left">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($exams as $exam)
                                <tr class="align-top transition hover:bg-slate-50">
                                    <td class="px-4 py-4">
                                        <div class="font-black text-slate-900">{{ $exam->judul_ujian }}</div>
                                        <div class="mt-1 font-mono text-xs font-bold tracking-widest text-blue-600">Token {{ $exam->token }}</div>
                                        @if(isset($lmsLinkedExamIds) && !$lmsLinkedExamIds->contains($exam->id))
                                            <span class="mt-2 inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2 py-1 text-[10px] font-bold text-indigo-700">LAN saja</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        @if(!empty($exam->tingkat_kelas))
                                            <span class="inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">Kelas {{ $exam->tingkat_kelas }}</span>
                                        @else
                                            <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-500">Legacy</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-center font-semibold text-slate-700">{{ $exam->durasi_menit > 0 ? $exam->durasi_menit.' menit' : 'Tanpa batas' }}</td>
                                    <td class="px-4 py-4 text-center"><span class="font-black text-slate-900">{{ $exam->questions_count }}</span><span class="ml-1 text-xs text-slate-400">soal</span></td>
                                    <td class="px-4 py-4 text-center">
                                        @if($exam->status === 'aktif')
                                            <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-black text-green-700">AKTIF</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-black text-slate-600">{{ strtoupper($exam->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        @if($exam->last_synced_at)
                                            <span class="inline-flex rounded-full border border-green-200 bg-green-50 px-2.5 py-1 text-[11px] font-black text-green-700">✓ v{{ $exam->sync_version }}</span>
                                            <span class="mt-1 block text-[10px] text-slate-400">{{ \Carbon\Carbon::parse($exam->last_synced_at)->diffForHumans() }}</span>
                                        @elseif($exam->sync_status === 'pending')
                                            <span class="inline-flex rounded-full border border-yellow-200 bg-yellow-50 px-2.5 py-1 text-[11px] font-black text-yellow-700">Menunggu</span>
                                        @else
                                            <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-bold text-slate-500">Belum dikirim</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex min-w-[360px] flex-wrap gap-2">
                                            <form data-eduxid-arena-cloud-publish="1" action="{{ route('guru.cbt.soal.store', $exam->id) }}" method="POST" onsubmit="return confirm('Bagikan salinan latihan dari paket tertutup ini ke Cloud khusus akun Guru Anda? Setelah ditarik ke PWA Guru, paket masih harus dipublikasikan ke kelas secara terpisah. Jangan gunakan soal ujian yang akan diaktifkan lagi.');">
                                                @csrf
                                                <input type="hidden" name="arena_cloud_publish" value="1">
                                                <button type="submit" class="eduxid-btn rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-bold text-cyan-800 hover:bg-cyan-100" {{ $exam->status === 'aktif' ? 'disabled' : '' }}>☁️ Kirim Paket ke PWA Guru (Cloud)</button>
                                            </form>
                                            <span class="block w-full text-[11px] text-slate-500">Mengirim salinan soal ke katalog privat Cloud. Bukan unduhan JSON; setelah sukses, buka PWA Guru → Arena Belajar → Tarik Paket.</span>
                                            <a href="{{ route('guru.cbt.soal', $exam->id) }}" class="eduxid-btn rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800 hover:bg-amber-100">Pilih Soal</a>
                                            <a href="{{ route('guru.ljk-auto.index', ['exam' => $exam->id]) }}" class="eduxid-btn rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100">Koreksi LJK</a>
                                            <a href="{{ route('guru.item-analysis.index', ['exam' => $exam->id, 'source' => 'combined']) }}" class="eduxid-btn rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-bold text-violet-700 hover:bg-violet-100">Analisis Butir</a>

                                            <form action="{{ route('guru.cbt.syncToStudents', $exam->id) }}" method="POST" onsubmit="return confirm('Kirim paket CBT ini ({{ $exam->questions_count }} soal, tanpa kunci jawaban) ke perangkat murid?')">
                                                @csrf
                                                <button type="submit" class="eduxid-btn rounded-lg bg-green-600 px-3 py-2 text-xs font-bold text-white hover:bg-green-700">Sinkronkan</button>
                                            </form>

                                            @if($exam->status === 'aktif')
                                                <form action="{{ route('guru.cbt.deactivate', $exam->id) }}" method="POST" onsubmit="return confirm('Tutup ujian ini?\n\nSemua sesi yang masih aktif akan dipaksa selesai menggunakan jawaban autosave terakhir. Soal yang belum dijawab menjadi kosong. Nilai tetap menunggu mekanisme koreksi resmi.')">
                                                    @csrf
                                                    <button type="submit" class="eduxid-btn rounded-lg bg-red-600 px-3 py-2 text-xs font-bold text-white hover:bg-red-700">Tutup Ujian</button>
                                                </form>
                                            @else
                                                <form action="{{ route('guru.cbt.activate', $exam->id) }}" method="POST" onsubmit="return confirm('Buka ujian ini? Status AKTIF akan dikirim ke perangkat murid yang tersinkron.')">
                                                    @csrf
                                                    <button type="submit" class="eduxid-btn rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700">Buka Ujian</button>
                                                </form>
                                            @endif

                                            <form action="{{ route('guru.cbt.destroy', $exam->id) }}" method="POST" onsubmit="return confirm('Hapus paket ujian ini?\n\nPaket, relasi soal, sesi CBT, hasil LJK, analisis butir, dan aktivitas LMS terkait akan dibersihkan. Nilai yang sudah resmi masuk Buku Nilai tetap dipertahankan. Penghapusan akan ditolak bila masih ada sesi aktif atau jawaban yang belum dikoreksi.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="eduxid-btn rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 hover:bg-red-100">Hapus</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <div class="font-bold text-slate-700">Belum ada paket ujian.</div>
                                        <div class="mt-1 text-sm text-slate-400">Buat paket pertama untuk mulai menambahkan soal dan mendistribusikannya.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="eduxid-page-card eduxid-page-card-slate overflow-hidden">
                <div class="flex flex-col gap-3 border-b border-slate-100 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="text-lg font-black text-slate-900">Monitoring Peserta Ujian</h3>
                        <p class="mt-1 text-xs text-slate-500">Pantau sesi LAN/PWA, status koreksi, nilai, dan tindakan Paksa Selesai.</p>
                    </div>
                    <a href="{{ route('guru.cbt.exportHasil') }}" target="_blank" class="eduxid-btn rounded-md bg-green-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-700">Download Rekap Excel</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white text-xs uppercase tracking-wide text-slate-500">
                            <tr class="border-b border-slate-200">
                                <th class="px-4 py-3 text-center">No.</th>
                                <th class="px-4 py-3 text-left">Murid</th>
                                <th class="px-4 py-3 text-left">NISN</th>
                                <th class="px-4 py-3 text-left">Kelas</th>
                                <th class="px-4 py-3 text-left">Paket Ujian</th>
                                <th class="px-4 py-3 text-center">Sumber</th>
                                <th class="px-4 py-3 text-center">Status</th>
                                <th class="px-4 py-3 text-center">Hasil</th>
                                <th class="px-4 py-3 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($sessions as $i => $s)
                                @php
                                    $sumberPwa = trim((string) ($s->sync_id ?? '')) !== '';
                                    $selesai = $s->status === 'selesai';
                                    $menungguKoreksi = $selesai && $s->nilai_akhir === null;
                                    $durasiMenit = (int) ($s->durasi_menit ?? 0);
                                    $waktuHabis = !empty($s->waktu_masuk) && $durasiMenit > 0
                                        ? \Carbon\Carbon::parse($s->waktu_masuk)->addMinutes($durasiMenit)
                                        : null;
                                    $bolehPaksaSelesai = !$selesai && $waktuHabis && now()->gte($waktuHabis);
                                @endphp
                                <tr class="transition hover:bg-slate-50">
                                    <td class="px-4 py-4 text-center text-slate-400">{{ $i + 1 }}</td>
                                    <td class="px-4 py-4">
                                        <div class="font-bold text-slate-900">{{ $s->nama }}</div>
                                        @if(!empty($s->feedback) && $s->nilai_akhir !== null)
                                            <div class="mt-1 text-[10px] font-semibold text-green-600">Feedback terkirim</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 font-mono text-xs text-slate-600">{{ $s->nisn }}</td>
                                    <td class="px-4 py-4">
                                        @if(!empty($s->kelas_reguler))
                                            <span class="inline-flex rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">{{ $s->kelas_reguler }}</span>
                                        @else
                                            <span class="text-xs text-slate-400">-</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-slate-700">{{ $s->judul_ujian }}</td>
                                    <td class="px-4 py-4 text-center">
                                        @if($sumberPwa)
                                            <span class="inline-flex rounded-full border border-purple-200 bg-purple-50 px-2.5 py-1 text-[10px] font-bold text-purple-700">PWA</span>
                                        @else
                                            <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-bold text-slate-600">LAN</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        @if(!$selesai)
                                            <span class="inline-flex rounded-full bg-blue-100 px-2.5 py-1 text-xs font-bold text-blue-700">MENGERJAKAN</span>
                                        @elseif($menungguKoreksi)
                                            <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-700">MENUNGGU KOREKSI</span>
                                        @else
                                            <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-700">SELESAI</span>
                                        @endif
                                        @if($selesai && $s->waktu_submit)
                                            <span class="mt-1 block text-[10px] text-slate-400">{{ \Carbon\Carbon::parse($s->waktu_submit)->translatedFormat('d M H:i') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        @if($s->nilai_akhir !== null)
                                            <span class="text-lg font-black text-blue-700">{{ $s->nilai_akhir }}</span>
                                        @elseif($menungguKoreksi)
                                            <a href="{{ route('guru.cbt.koreksi.show', ['session' => $s->id]) }}" class="eduxid-btn rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700">Koreksi</a>
                                        @else
                                            <span class="text-slate-300">-</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-4 text-center">
                                        @if($bolehPaksaSelesai)
                                            <form method="POST" action="{{ route('guru.cbt.forceFinish', ['session' => $s->id]) }}" onsubmit="return confirm('Paksa selesaikan ujian murid ini?\n\nJawaban terakhir yang sudah tersimpan di server akan dijadikan jawaban final. Soal yang belum dijawab menjadi kosong. Penilaian tetap dilakukan melalui koreksi resmi.')">
                                                @csrf
                                                <button type="submit" class="eduxid-btn rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-red-700">Paksa Selesai</button>
                                            </form>
                                        @elseif(!$selesai && $waktuHabis)
                                            <span class="text-[10px] text-slate-400">Berakhir {{ $waktuHabis->format('H:i') }}</span>
                                        @else
                                            <span class="text-slate-300">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-12 text-center">
                                        <div class="font-bold text-slate-700">Belum ada sesi peserta.</div>
                                        <div class="mt-1 text-sm text-slate-400">Data akan muncul setelah murid mulai mengerjakan paket ujian.</div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
