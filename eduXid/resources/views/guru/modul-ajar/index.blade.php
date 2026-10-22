{{-- eduXid V11: tampilan KHUSUS Modul Ajar mengikuti prototipe, menu utama tetap desain lama V10. --}}
@push('styles')
<link rel="stylesheet" href="{{ asset('css/eduxid-modul-ajar-v11.css') }}?v=20261012-v11">
@endpush
@php
    $fields = old('content', $content);
    $selectedAtp = old('atp_item_id', $module->atp_item_id ?? '');
    $selectedTitle = old('title', $module->title ?? '');
    $selectedDuration = old('duration_jp', $module->duration_jp ?? 2);
    $steps = [
        ['Identitas', 'Konteks pembelajaran'],
        ['ATP & Tujuan', 'Terhubung ATP'],
        ['Profil Lulusan', '8 dimensi · 3 prinsip'],
        ['Desain', '4 kerangka PM'],
        ['Pengalaman Belajar', '3 tahapan'],
        ['Asesmen', 'Awal · Proses · Akhir'],
        ['Lampiran', 'Referensi & tindak lanjut'],
    ];
    $atpOptions = $atpItems->map(fn ($a) => [
        'id' => (int) $a->id, 'materi' => (string) $a->materi,
        'tujuan' => (string) $a->tujuan_pembelajaran,
        'indikator' => (string) $a->indikator_penilaian,
    ])->values()->all();
    $dimensi = \App\Services\ModulAjarGenerator::DIMENSIONS;
    $prinsip = ['Berkesadaran', 'Bermakna', 'Menggembirakan'];
    $phase = $grade <= 2 ? 'A' : ($grade <= 4 ? 'B' : ($grade <= 6 ? 'C' : ($grade <= 9 ? 'D' : ($grade == 10 ? 'E' : 'F'))));
@endphp
<x-app-layout>
<div class="ma-v11" id="ma-page">
    <div class="ma-container">
        <p class="ma-breadcrumb">Guru / Perencanaan Pembelajaran / <strong>Modul Ajar</strong></p>
        <div class="ma-header">
            <div>
                <div class="ma-eyebrow">PERENCANAAN PEMBELAJARAN MENDALAM</div>
                <h1>Modul Ajar</h1>
                <p>Susun pembelajaran Kurikulum Merdeka dari ATP hingga asesmen, dalam satu halaman.</p>
            </div>
            <div class="ma-actions">
                <button type="button" id="ma-preview-top" class="ma-btn ma-btn-secondary" @disabled(!$subjectId || !$grade)>▤ Pratinjau & Cetak</button>
                <button type="button" id="ma-export" class="ma-btn ma-btn-secondary" @disabled(!$subjectId || !$grade)>↓ Draf JSON</button>
                <button type="submit" form="ma-form" class="ma-btn ma-btn-primary" @disabled(!$subjectId || !$grade)>▣ {{ $module ? 'Simpan Perubahan' : 'Simpan Draf' }}</button>
            </div>
        </div>

        @if(session('success'))<div role="status" class="ma-alert good">{{ session('success') }}</div>@endif
        @if(session('error'))<div role="alert" class="ma-alert bad">{{ session('error') }}</div>@endif
        @if($errors->any())
            <div role="alert" class="ma-alert bad"><strong>Periksa kembali isian Modul Ajar:</strong>
                <ul style="list-style:disc;padding-left:22px;margin-top:6px">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="ma-card ma-scope">
            <div class="ma-scope-intro">
                <strong>Penugasan Mengajar</strong>
                <p>Identitas mata pelajaran dan tingkat kelas berasal dari penugasan GTK. Ubah pilihan untuk membuat modul pada konteks lain.</p>
            </div>
            <form id="ma-scope-form" method="GET" action="{{ route('guru.modul-ajar.index') }}" class="ma-scope-selects">
                <label><span class="ma-field-label">Mata Pelajaran</span>
                    <select name="subject" class="ma-select" aria-label="Pilih Mata Pelajaran" data-ma-scope>
                        @forelse($subjects as $subject)
                            <option value="{{ $subject->id }}" @selected((int)$subjectId === (int)$subject->id)>{{ $subject->nama_mata_pelajaran }}</option>
                        @empty<option value="">Belum ada penugasan</option>@endforelse
                    </select>
                </label>
                <label><span class="ma-field-label">Tingkat Kelas</span>
                    <select name="grade" class="ma-select" aria-label="Pilih Tingkat Kelas" data-ma-scope>
                        @forelse($grades as $g)
                            <option value="{{ $g }}" @selected((int)$grade === (int)$g)>Kelas {{ $g }}</option>
                        @empty<option value="">Belum ada kelas</option>@endforelse
                    </select>
                </label>
            </form>
        </div>

        @if($module)
            <p class="ma-hint" style="margin:-10px 0 18px">Anda sedang menyunting modul tersimpan. Mengubah penugasan akan membuka editor modul baru; modul ini tetap tersimpan.</p>
        @endif

        @if($subjectId > 0 && $grade > 0)
        <div class="ma-layout" id="ma-editor">
            <aside class="ma-card ma-stepnav" aria-label="Bagian Modul Ajar">
                <div class="ma-navlabel">BAGIAN MODUL</div>
                @foreach($steps as $i => $step)
                    <button type="button" data-ma-step="{{ $i }}" class="ma-step {{ $i === 0 ? 'is-active' : '' }}" aria-current="{{ $i === 0 ? 'step' : 'false' }}">
                        <span class="ma-step-number">{{ $i + 1 }}</span>
                        <span>{{ $step[0] }}<span class="ma-step-caption">{{ $step[1] }}</span></span>
                    </button>
                @endforeach
                <div class="ma-navnote">Tiap bagian dapat diedit terpisah. Tekan <strong>Simpan Draf</strong> untuk menyimpan ke database lokal eduXid.</div>
            </aside>

            <div class="ma-editor-column">
                <section class="ma-card ma-assistant" aria-label="Penyusunan Otomatis">
                    <div class="ma-assistant-head">
                        <div style="flex:1 1 240px">
                            <span class="ma-kicker">PENYUSUN OTOMATIS</span>
                            <h2>Isi Modul Ajar dari ATP</h2>
                            <p>
                                @if($aiProvider === 'offline')
                                    Mode: <strong>Template offline (bukan AI generatif)</strong>.
                                @elseif($aiProvider === 'ollama')
                                    Mode: <strong>AI lokal Ollama</strong> — memerlukan model yang sudah dikonfigurasi.
                                @else
                                    Mode: <strong>AI OpenAI</strong> — memerlukan internet dan API key operator.
                                @endif
                            </p>
                        </div>
                        <button id="ma-generate" type="button" class="ma-btn ma-btn-primary"><span id="ma-generate-text">{{ $aiProvider === 'offline' ? 'Isi Otomatis dari ATP' : 'Susun dengan AI' }}</span></button>
                    </div>
                    <p class="ma-notice">Penyusun otomatis mengisi formulir, bukan langsung menyimpan. Guru tetap memeriksa CP, TP, indikator, kegiatan, dan asesmen sebelum menyimpan. Mode API hanya mengirim konteks mapel/ATP, bukan nama murid.</p>
                    <p id="ma-result" role="status" aria-live="polite" class="ma-feedback ma-hidden"></p>
                </section>

                <form id="ma-form" novalidate method="POST" action="{{ $module ? route('guru.modul-ajar.update', $module->id) : route('guru.modul-ajar.store') }}">
                    @csrf
                    @if($module) @method('PUT') @endif
                    <input type="hidden" name="subject_id" value="{{ $subjectId }}">
                    <input type="hidden" name="grade_level" value="{{ $grade }}">
                    <input type="hidden" id="ma-source" name="generation_source" value="{{ old('generation_source', $module->generation_source ?? 'manual') }}">
                    <div class="ma-card ma-panel">
                        <section class="ma-pane is-active" data-ma-pane="0">
                            <div class="ma-panel-top"><h2>1. Identitas Modul Ajar</h2><p>Identitas dasar berasal dari akun guru dan penugasan mengajar yang aktif.</p></div>
                            <div class="ma-panel-body">
                                <div class="ma-grid2">
                                    <div class="ma-field"><span class="ma-field-label">Nama Guru</span><div class="ma-readonly" id="ma-teacher-name">{{ $teacher->nama ?? auth()->user()->name }}</div></div>
                                    <div class="ma-field"><span class="ma-field-label">Mata Pelajaran</span><div class="ma-readonly" id="ma-subject-name">{{ $subjectName }}</div></div>
                                    <div class="ma-field"><span class="ma-field-label">Tingkat Kelas / Fase</span><div class="ma-readonly">Kelas {{ $grade }} · Fase {{ $phase }}</div></div>
                                    <div class="ma-field"><span class="ma-field-label">Semester Aktif</span><div class="ma-readonly">{{ $module->semester_id ?? app(\App\Services\AtpService::class)->semesterId() ?? 'Belum ditetapkan' }}</div></div>
                                    <label class="ma-field"><span class="ma-field-label">Alokasi Waktu (JP) <span class="ma-required">*</span></span>
                                        <input id="ma-jp" name="duration_jp" type="number" min="1" max="60" required value="{{ $selectedDuration }}" class="ma-input">
                                        <span class="ma-hint">Durasi 1 JP mengikuti jenjang dan ketentuan sekolah.</span>
                                    </label>
                                    <div class="ma-field"><span class="ma-field-label">Status Dokumen</span><div class="ma-readonly">{{ $module ? 'Draf tersimpan' : 'Draf baru' }}</div></div>
                                </div>
                                <label class="ma-field"><span class="ma-field-label">Judul Modul Ajar <span class="ma-required">*</span></span>
                                    <input name="title" id="ma-title" class="ma-input" required maxlength="255" value="{{ $selectedTitle }}" placeholder="Contoh: Vektor dalam Kehidupan Sehari-hari">
                                </label>
                                <label class="ma-field"><span class="ma-field-label">Karakteristik dan Kesiapan Murid</span>
                                    <textarea name="content[karakteristik]" class="ma-textarea" rows="4" placeholder="Hasil asesmen awal, ragam kesiapan murid, dukungan yang dibutuhkan...">{{ $fields['karakteristik'] ?? '' }}</textarea>
                                </label>
                                <div class="ma-bluebox"><strong>Integrasi eduXid</strong>Identitas guru, mapel, dan kelas berasal dari data lokal eduXid. Tidak diperlukan pengiriman identitas murid untuk menyusun modul.</div>
                            </div>
                        </section>

                        <section class="ma-pane" data-ma-pane="1">
                            <div class="ma-panel-top"><h2>2. ATP, Tujuan, dan Indikator Penilaian</h2><p>Ambil ATP yang sudah dibuat, lalu sesuaikan isi modul tanpa mengubah data ATP sumbernya.</p></div>
                            <div class="ma-panel-body">
                                <label class="ma-field"><span class="ma-field-label">Pilih ATP tersedia</span>
                                    <select id="ma-atp" name="atp_item_id" class="ma-select">
                                        <option value="">Isi manual (tanpa ATP)</option>
                                        @foreach($atpItems as $item)
                                            <option value="{{ $item->id }}" @selected((string)$selectedAtp === (string)$item->id)>{{ $item->sort_order }}. {{ $item->materi }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <div style="margin:-4px 0 20px"><button type="button" class="ma-btn ma-btn-soft" id="ma-copy-atp">↳ Ambil Data dari ATP</button>
                                    @if($atpItems->isEmpty())<p class="ma-hint">Belum ada ATP pada penugasan ini. Modul Ajar tetap dapat diisi manual.</p>@endif
                                </div>
                                @foreach([
                                    'materi' => ['Materi / Lingkup Materi', 2],
                                    'cp' => ['Capaian Pembelajaran (CP)', 3],
                                    'tujuan' => ['Tujuan Pembelajaran', 4],
                                    'indikator' => ['Indikator Ketercapaian / Penilaian', 4],
                                    'pemantik' => ['Pertanyaan Pemantik', 3],
                                ] as $key => $def)
                                    <label class="ma-field"><span class="ma-field-label">{{ $def[0] }}</span>
                                        <textarea class="ma-textarea" name="content[{{ $key }}]" rows="{{ $def[1] }}" @if($key === 'cp') placeholder="Gunakan CP resmi mata pelajaran dan fase yang berlaku." @endif>{{ $fields[$key] ?? '' }}</textarea>
                                    </label>
                                @endforeach
                            </div>
                        </section>

                        <section class="ma-pane" data-ma-pane="2">
                            <div class="ma-panel-top"><h2>3. Delapan Dimensi Profil Lulusan</h2><p>Pilih dimensi yang akan dikuatkan, sesuai dengan tujuan dan kegiatan pembelajaran.</p></div>
                            <div class="ma-panel-body">
                                <div class="ma-checkgrid">
                                    @foreach($dimensi as $code => $name)
                                        <label class="ma-checkitem"><input type="checkbox" name="content[dimensi][]" value="{{ $code }}" @checked(in_array($code, $fields['dimensi'] ?? []))><span><strong>{{ $code }}</strong> · {{ $name }}</span></label>
                                    @endforeach
                                </div>
                                <h3 style="font-weight:800;font-size:14px;margin:24px 0 10px">Tiga Prinsip Pembelajaran Mendalam</h3>
                                <div class="ma-principles">
                                    @foreach($prinsip as $p)
                                        <label class="ma-principle"><strong>{{ $p }}</strong><small><input type="checkbox" name="content[prinsip][]" value="{{ $p }}" @checked(in_array($p, $fields['prinsip'] ?? []))> Pilih prinsip</small></label>
                                    @endforeach
                                </div>
                                <label class="ma-field"><span class="ma-field-label">Perwujudan Dimensi dalam Kegiatan Belajar</span>
                                    <textarea name="content[profil_strategi]" rows="5" class="ma-textarea">{{ $fields['profil_strategi'] ?? '' }}</textarea>
                                </label>
                                <div class="ma-bluebox"><strong>Prinsip menjadi acuan kegiatan</strong>Pastikan Berkesadaran, Bermakna, dan Menggembirakan tercermin pada pengalaman Memahami, Mengaplikasi, dan Merefleksi.</div>
                            </div>
                        </section>

                        <section class="ma-pane" data-ma-pane="3">
                            <div class="ma-panel-top"><h2>4. Desain Pembelajaran Mendalam</h2><p>Lengkapi empat komponen kerangka pembelajaran dan diferensiasi sesuai kondisi sekolah.</p></div>
                            <div class="ma-panel-body">
                                @foreach([
                                    'pedagogi' => 'Praktik Pedagogis', 'kemitraan' => 'Kemitraan Pembelajaran',
                                    'lingkungan' => 'Lingkungan Pembelajaran', 'digital' => 'Pemanfaatan Digital',
                                    'diferensiasi' => 'Diferensiasi dan Dukungan Kebutuhan Murid',
                                ] as $key => $label)
                                    <label class="ma-field"><span class="ma-field-label">{{ $label }}</span><textarea name="content[{{ $key }}]" rows="4" class="ma-textarea">{{ $fields[$key] ?? '' }}</textarea></label>
                                @endforeach
                            </div>
                        </section>

                        <section class="ma-pane" data-ma-pane="4">
                            <div class="ma-panel-top"><h2>5. Pengalaman Belajar</h2><p>Rancang pengalaman berurutan: Memahami → Mengaplikasi → Merefleksi, beserta pendahuluan dan penutup.</p></div>
                            <div class="ma-panel-body">
                                <label class="ma-field"><span class="ma-field-label">Pendahuluan</span><textarea name="content[pendahuluan]" rows="4" class="ma-textarea">{{ $fields['pendahuluan'] ?? '' }}</textarea></label>
                                @foreach([
                                    'memahami' => ['01 · Memahami', 'Membangun makna melalui pengamatan dan eksplorasi konsep.'],
                                    'mengaplikasi' => ['02 · Mengaplikasi', 'Memecahkan masalah kontekstual dan memperoleh umpan balik.'],
                                    'merefleksi' => ['03 · Merefleksi', 'Mengevaluasi proses dan merencanakan tindak lanjut.'],
                                ] as $key => $def)
                                    <div class="ma-stage"><h3>{{ $def[0] }} <span class="ma-pill">Pembelajaran Mendalam</span></h3><p>{{ $def[1] }}</p>
                                        <label class="ma-field" style="margin-bottom:0"><span class="ma-field-label">Aktivitas Murid, Media, dan Bukti Belajar</span><textarea name="content[{{ $key }}]" rows="5" class="ma-textarea">{{ $fields[$key] ?? '' }}</textarea></label>
                                    </div>
                                @endforeach
                                <label class="ma-field"><span class="ma-field-label">Penutup</span><textarea name="content[penutup]" rows="4" class="ma-textarea">{{ $fields['penutup'] ?? '' }}</textarea></label>
                                <div class="ma-bluebox"><strong>Catatan durasi</strong>Alokasi waktu keseluruhan mengikuti JP pada Identitas. Guru dapat membagi kegiatan menjadi beberapa pertemuan sesuai jadwal.</div>
                            </div>
                        </section>

                        <section class="ma-pane" data-ma-pane="5">
                            <div class="ma-panel-top"><h2>6. Asesmen dan Tindak Lanjut</h2><p>Rancang asesmen awal, proses, dan akhir yang selaras dengan indikator dalam ATP.</p></div>
                            <div class="ma-panel-body">
                                @foreach([
                                    'diagnostik'=>'Asesmen Awal / Diagnostik', 'formatif'=>'Asesmen Proses / Formatif',
                                    'sumatif'=>'Asesmen Akhir / Sumatif', 'rubrik'=>'Kriteria / Rubrik Penilaian',
                                    'remedial'=>'Remedial dan Pengayaan',
                                ] as $key => $label)
                                    <label class="ma-field"><span class="ma-field-label">{{ $label }}</span><textarea name="content[{{ $key }}]" rows="4" class="ma-textarea">{{ $fields[$key] ?? '' }}</textarea></label>
                                @endforeach
                                <div class="ma-bluebox"><strong>Hubungan dengan Buku Nilai</strong>Menyimpan Modul Ajar tidak membuat nilai baru. Guru memasukkan nilai melalui mekanisme Buku Nilai yang sudah ada.</div>
                            </div>
                        </section>

                        <section class="ma-pane" data-ma-pane="6">
                            <div class="ma-panel-top"><h2>7. Lampiran, Referensi, dan Penyelesaian</h2><p>Lengkapi rujukan, refleksi guru, dan periksa dokumen sebelum dicetak.</p></div>
                            <div class="ma-panel-body">
                                <label class="ma-field"><span class="ma-field-label">Daftar LKPD, Bahan Ajar, dan Referensi</span><textarea name="content[lampiran]" rows="6" class="ma-textarea">{{ $fields['lampiran'] ?? '' }}</textarea></label>
                                <label class="ma-field"><span class="ma-field-label">Catatan Refleksi Guru (setelah pembelajaran)</span><textarea name="content[refleksi_guru]" rows="5" class="ma-textarea">{{ $fields['refleksi_guru'] ?? '' }}</textarea></label>
                                <div class="ma-bluebox"><strong>Dokumen tersimpan di laptop guru</strong>Gunakan Pratinjau untuk memeriksa, Simpan Draf untuk menyimpan ke SQLite, dan Cetak untuk PDF. Penerbitan LMS atau unggah lampiran belum otomatis dilakukan.</div>
                                <div class="ma-actions" style="margin:15px 0 6px"><button type="button" id="ma-finish-preview" class="ma-btn ma-btn-secondary">▤ Pratinjau Modul Ajar</button>
                                    <button type="submit" class="ma-btn ma-btn-primary">▣ {{ $module ? 'Simpan Perubahan' : 'Simpan Draf' }}</button>
                                </div>
                            </div>
                        </section>
                        <div class="ma-panel-foot">
                            <button type="button" id="ma-prev" class="ma-btn ma-btn-secondary" disabled>← Sebelumnya</button>
                            <span id="ma-step-indicator" class="ma-panel-foot-note">Bagian 1 dari 7</span>
                            <button type="button" id="ma-next" class="ma-btn ma-btn-primary">Berikutnya →</button>
                        </div>
                    </div>
                </form>
            </div>

            <aside class="ma-sideinfo">
                <section class="ma-card ma-info">
                    <h3>Progres Penyusunan</h3>
                    <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px"><strong id="ma-progress-number" class="ma-progress-value">0%</strong><span style="font-size:11px;color:#8798aa">kelengkapan inti</span></div>
                    <div class="ma-progress-track"><i class="ma-progress-fill" id="ma-progress-bar"></i></div>
                    <p>Progres berdasarkan keterisian bidang, bukan penilaian mutu pedagogis.</p>
                    <div class="ma-stat"><span>ATP</span><strong id="ma-atp-status">Belum lengkap</strong></div>
                    <div class="ma-stat"><span>Profil Lulusan</span><strong id="ma-dimension-count">0 dimensi</strong></div>
                    <div class="ma-stat"><span>Pengalaman Belajar</span><strong id="ma-stage-count">0 tahapan</strong></div>
                    <div class="ma-stat"><span>Status</span><strong id="ma-document-status">{{ $module ? 'Draf tersimpan' : 'Belum disimpan' }}</strong></div>
                </section>
                <section class="ma-card ma-info">
                    <h3>Pratinjau Singkat</h3>
                    <div class="ma-mini">
                        <span class="ma-smalltag">MODUL AJAR</span>
                        <h4 id="ma-mini-title">{{ $selectedTitle ?: 'Judul belum diisi' }}</h4>
                        <p id="ma-mini-meta">{{ $subjectName }} · Kelas {{ $grade }} · Fase {{ $phase }}</p>
                        <hr style="border:0;border-top:1px solid #e7edf6;margin:12px 0">
                        <p id="ma-mini-goal">Tujuan Pembelajaran belum diisi</p>
                    </div>
                    <button type="button" id="ma-side-preview" class="ma-btn ma-btn-soft" style="width:100%;margin-top:14px">Lihat Pratinjau Lengkap ↗</button>
                </section>
                <section class="ma-card ma-info"><h3>Offline-first</h3><div class="ma-bluebox" style="margin:0">Draf tersimpan di SQLite laptop guru. Mode AI OpenAI menggunakan internet hanya ketika diminta. Data tidak otomatis diterbitkan ke LMS atau Cloud.</div>
                    @if($module)<a href="{{ route('guru.modul-ajar.print', $module->id) }}" target="_blank" rel="noopener" class="ma-btn ma-btn-secondary" style="width:100%;margin-top:12px">Cetak Modul Tersimpan ↗</a>@endif
                </section>
            </aside>
        </div>
        @else
            <div role="alert" class="ma-alert bad">Belum ditemukan penugasan mata pelajaran dan tingkat kelas yang aktif. Silakan periksa penugasan Dapodik/GTK.</div>
        @endif

        <section class="ma-card ma-archive" aria-label="Arsip Modul Ajar">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div><h2 style="font-size:17px;font-weight:800;margin:0">Arsip Modul Ajar Saya</h2><p class="ma-muted" style="font-size:12px;margin:5px 0 0">Modul tersimpan di database lokal. Semua aksi mengikuti kepemilikan guru.</p></div>
                <span class="ma-smalltag">{{ $modules->count() }} modul terbaru</span>
            </div>
            @forelse($modules as $item)
                <div class="ma-archive-row">
                    <div style="flex:1 1 240px;min-width:0">
                        <div class="ma-archive-title">{{ $item->title }}</div>
                        <div class="ma-archive-meta">Kelas {{ $item->grade_level }} · Fase {{ $item->phase }} · {{ $item->duration_jp }} JP · {{ $item->updated_at }} · {{ strtoupper($item->generation_source) }}</div>
                    </div>
                    <div class="ma-actions">
                        <a class="ma-btn ma-btn-primary" href="{{ route('guru.modul-ajar.edit', $item->id) }}">Edit</a>
                        <a class="ma-btn ma-btn-secondary" href="{{ route('guru.modul-ajar.print', $item->id) }}" target="_blank" rel="noopener">Cetak</a>
                        <form method="POST" action="{{ route('guru.modul-ajar.duplicate', $item->id) }}">@csrf<button type="submit" class="ma-btn ma-btn-secondary">Duplikasi</button></form>
                        <form method="POST" action="{{ route('guru.modul-ajar.destroy', $item->id) }}" onsubmit="return confirm('Hapus modul ini dari penyimpanan lokal?');">@csrf @method('DELETE')<button type="submit" class="ma-btn ma-btn-danger">Hapus</button></form>
                    </div>
                </div>
            @empty
                <p class="ma-muted" style="font-size:13px;margin:15px 0 0">Belum ada Modul Ajar tersimpan. Pilih penugasan dan ATP, lalu mulailah menyusun.</p>
            @endforelse
        </section>
    </div>

    {{-- Modal pratinjau isi FORMULIR (termasuk perubahan yang belum disimpan). --}}
    <div id="ma-preview-modal" class="ma-preview-overlay" role="dialog" aria-modal="true" aria-label="Pratinjau Modul Ajar" aria-hidden="true">
        <div class="ma-preview-dialog">
            <div class="ma-preview-bar"><strong>Pratinjau Dokumen Modul Ajar</strong>
                <div class="ma-actions"><button type="button" class="ma-btn ma-btn-primary" id="ma-print-current">▤ Cetak / Simpan PDF</button><button type="button" class="ma-btn ma-btn-secondary" id="ma-close-preview">✕ Tutup</button></div>
            </div>
            <article class="ma-preview-paper" id="ma-preview-paper"></article>
        </div>
    </div>
</div>
@if($subjectId > 0 && $grade > 0)
<script>
document.addEventListener('DOMContentLoaded', () => {
    'use strict';
    const page = document.getElementById('ma-page');
    const form = document.getElementById('ma-form');
    const steps = [...page.querySelectorAll('[data-ma-step]')];
    const panes = [...page.querySelectorAll('[data-ma-pane]')];
    const atpItems = @json($atpOptions);
    const dims = @json($dimensi);
    const titleField = document.getElementById('ma-title');
    const previous = document.getElementById('ma-prev');
    const next = document.getElementById('ma-next');
    const modal = document.getElementById('ma-preview-modal');
    let active = 0;
    let dirty = false;

    function showStep(n) {
        active = Math.max(0, Math.min(panes.length - 1, n));
        panes.forEach((pane, i) => pane.classList.toggle('is-active', i === active));
        steps.forEach((button, i) => {
            button.classList.toggle('is-active', i === active);
            button.setAttribute('aria-current', i === active ? 'step' : 'false');
        });
        previous.disabled = active === 0;
        next.textContent = active === panes.length - 1 ? 'Pratinjau →' : 'Berikutnya →';
        document.getElementById('ma-step-indicator').textContent = 'Bagian ' + (active + 1) + ' dari ' + panes.length;
    }
    steps.forEach((b, i) => b.addEventListener('click', () => showStep(i)));
    previous.addEventListener('click', () => showStep(active - 1));
    next.addEventListener('click', () => active === panes.length - 1 ? preview() : showStep(active + 1));
    function getText(key) { return form.elements.namedItem('content[' + key + ']')?.value || ''; }
    function fill(key, value) { const input = form.elements.namedItem('content[' + key + ']'); if (input && typeof value === 'string') input.value = value; }
    function selectedAtp() { const id = form.elements.namedItem('atp_item_id').value; return atpItems.find(item => String(item.id) === id); }
    function notify(msg, error = false) {
        const el = document.getElementById('ma-result');
        el.textContent = msg;
        el.className = 'ma-feedback ' + (error ? 'fail' : 'ok');
    }
    function changed() {
        dirty = true;
        const source = document.getElementById('ma-document-status');
        source.textContent = 'Perubahan belum disimpan';
        updateProgress();
    }
    document.getElementById('ma-copy-atp').addEventListener('click', () => {
        const item = selectedAtp();
        if (!item) { notify('Pilih ATP terlebih dahulu.', true); showStep(1); return; }
        if (!confirm('Salin materi, tujuan, dan indikator dari ATP? Isian ketiganya akan diganti.')) return;
        fill('materi', item.materi);
        fill('tujuan', item.tujuan);
        fill('indikator', item.indikator);
        if (!titleField.value.trim()) titleField.value = item.materi;
        document.getElementById('ma-source').value = 'manual';
        changed(); showStep(1);
        notify('Materi, TP, dan indikator telah disalin dari ATP. Periksa dan simpan draf.');
    });
    document.getElementById('ma-generate').addEventListener('click', async function () {
        if (!titleField.value.trim()) { const item = selectedAtp(); if (item) titleField.value = item.materi; }
        if (!titleField.value.trim()) { showStep(0); titleField.focus(); notify('Isi judul atau pilih ATP terlebih dahulu.', true); return; }
        if (!confirm('Penyusunan otomatis akan mengganti seluruh bidang Modul Ajar. Simpan draf sebelumnya terlebih dahulu jika diperlukan. Lanjutkan?')) return;
        this.disabled = true;
        const text = document.getElementById('ma-generate-text');
        text.textContent = 'Menyusun modul…';
        try {
            const response = await fetch(@json(route('guru.modul-ajar.generate')), {
                method: 'POST', credentials: 'same-origin',
                headers: {'X-CSRF-TOKEN': @json(csrf_token()), 'Accept':'application/json','Content-Type':'application/json'},
                body: JSON.stringify({subject_id:Number(form.elements.namedItem('subject_id').value),
                    grade_level:Number(form.elements.namedItem('grade_level').value),
                    atp_item_id:Number(form.elements.namedItem('atp_item_id').value)||null,
                    duration_jp:Number(form.elements.namedItem('duration_jp').value),title:titleField.value.trim()})
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Penyusunan gagal (HTTP ' + response.status + ').');
            Object.entries(result.content || {}).forEach(([key, val]) => {
                if (Array.isArray(val) && ['dimensi','prinsip'].includes(key)) {
                    form.querySelectorAll('input[name="content[' + key + '][]"]').forEach(c => c.checked = val.includes(c.value));
                } else fill(key, val);
            });
            document.getElementById('ma-source').value = result.source || 'manual';
            changed(); showStep(1);
            notify(result.source === 'offline' ? 'Template ATP terisi offline (bukan AI generatif). Tinjau lalu simpan.' : 'Draf AI berhasil disusun. Periksa semua bagian sebelum menyimpan.');
        } catch(e) {
            notify(e.message || 'Penyusunan gagal. Periksa konfigurasi dan koneksi AI.', true);
        } finally {
            this.disabled = false;
            text.textContent = @json($aiProvider === 'offline' ? 'Isi Otomatis dari ATP' : 'Susun dengan AI');
        }
    });
    const progressKeys = ['karakteristik','cp','materi','tujuan','indikator','profil_strategi','pedagogi','kemitraan','lingkungan','digital','memahami','mengaplikasi','merefleksi','diagnostik','formatif','sumatif','rubrik','remedial'];
    function selections(key) { return [...form.querySelectorAll('input[name="content[' + key + '][]"]:checked')].map(c => c.value); }
    function updateProgress() {
        const complete = progressKeys.filter(key => getText(key).trim() !== '').length;
        const rate = Math.round(100 * complete / progressKeys.length);
        document.getElementById('ma-progress-number').textContent = rate + '%';
        document.getElementById('ma-progress-bar').style.width = rate + '%';
        document.getElementById('ma-atp-status').textContent = getText('tujuan').trim() && getText('indikator').trim() ? 'Terisi' : 'Belum lengkap';
        document.getElementById('ma-dimension-count').textContent = selections('dimensi').length + ' dimensi';
        document.getElementById('ma-stage-count').textContent = ['memahami','mengaplikasi','merefleksi'].filter(key => getText(key).trim()).length + ' tahapan';
        document.getElementById('ma-mini-title').textContent = titleField.value.trim() || 'Judul belum diisi';
        document.getElementById('ma-mini-goal').textContent = getText('tujuan').trim().slice(0,155) || 'Tujuan Pembelajaran belum diisi';
    }
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('submit', event => {
        if (!form.checkValidity()) {
            const invalid = form.querySelector(':invalid');
            if (invalid) {
                const pane = invalid.closest('[data-ma-pane]');
                if (pane) showStep(panes.indexOf(pane));
                invalid.focus();
            }
            event.preventDefault();
            form.reportValidity();
        } else dirty = false;
    });
    const scopeOriginal = Object.fromEntries([...page.querySelectorAll('[data-ma-scope]')].map(el => [el.name, el.value]));
    page.querySelectorAll('[data-ma-scope]').forEach(select => select.addEventListener('change', () => {
        if (dirty && !confirm('Ada perubahan Modul Ajar yang belum disimpan. Pindah penugasan dan tinggalkan perubahan?')) {
            select.value = scopeOriginal[select.name]; return;
        }
        // Pada pergantian mapel, rombel lama mungkin tidak termasuk penugasan mapel baru.
        // Kirim hanya subject; controller akan memilih kelas pertama yang sah.
        if (select.name === 'subject') page.querySelector('#ma-scope-form [name="grade"]').disabled = true;
        document.getElementById('ma-scope-form').submit();
    }));

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    }
    function preview() {
        const lines = [['Identifikasi',[['Karakteristik dan Kesiapan Murid','karakteristik']]],
          ['ATP dan Tujuan',[['Capaian Pembelajaran','cp'],['Materi / Lingkup Materi','materi'],['Tujuan Pembelajaran','tujuan'],['Indikator Penilaian','indikator'],['Pertanyaan Pemantik','pemantik']]],
          ['Profil Lulusan',[['Strategi Penerapan Profil Lulusan','profil_strategi']]],
          ['Desain Pembelajaran',[['Praktik Pedagogis','pedagogi'],['Kemitraan','kemitraan'],['Lingkungan','lingkungan'],['Pemanfaatan Digital','digital'],['Diferensiasi','diferensiasi']]],
          ['Pengalaman Belajar',[['Pendahuluan','pendahuluan'],['Memahami','memahami'],['Mengaplikasi','mengaplikasi'],['Merefleksi','merefleksi'],['Penutup','penutup']]],
          ['Asesmen',[['Diagnostik','diagnostik'],['Formatif','formatif'],['Sumatif','sumatif'],['Rubrik','rubrik'],['Remedial / Pengayaan','remedial']]],
          ['Lampiran dan Refleksi',[['Lampiran','lampiran'],['Refleksi Guru','refleksi_guru']]]];
        const title = titleField.value.trim() || 'Modul Ajar (Belum Berjudul)';
        let doc = '<h1>MODUL AJAR</h1><p class="ma-preview-caption">Kurikulum Merdeka · Perencanaan Pembelajaran Mendalam</p>';
        const meta = [['Judul', title],['Guru', document.getElementById('ma-teacher-name').textContent],
            ['Mata Pelajaran', document.getElementById('ma-subject-name').textContent],
            ['Tingkat / Fase', @json('Kelas '.$grade.' / Fase '.$phase)],['Alokasi Waktu',form.elements.namedItem('duration_jp').value + ' JP']];
        doc += '<dl>' + meta.map(([key,value]) => '<dt>' + escapeHtml(key) + '</dt><dd>' + escapeHtml(value) + '</dd>').join('') + '</dl>';
        lines.forEach(([heading,fields]) => {
            doc += '<h2>' + escapeHtml(heading) + '</h2>';
            if (heading === 'Profil Lulusan') doc += '<p><strong>Dimensi:</strong> ' + escapeHtml(selections('dimensi').map(d => d + ' ' + (dims[d] || '')).join(', ') || 'Belum dipilih') + '<br><strong>Prinsip:</strong> ' + escapeHtml(selections('prinsip').join(', ') || 'Belum dipilih') + '</p>';
            fields.forEach(([label,key]) => doc += '<div style="break-inside:avoid"><strong>' + escapeHtml(label) + '</strong><p>' + escapeHtml(getText(key) || 'Belum diisi') + '</p></div>');
        });
        doc += '<p style="text-align:right;margin-top:35px">Guru Pengampu,<br><br><br>' + escapeHtml(document.getElementById('ma-teacher-name').textContent) + '</p>';
        document.getElementById('ma-preview-paper').innerHTML = doc;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden','false');
        document.getElementById('ma-close-preview').focus();
    }
    function closePreview() {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden','true');
        document.body.classList.remove('ma-modul-print-mode');
    }
    ['ma-preview-top','ma-side-preview','ma-finish-preview'].forEach(id => document.getElementById(id)?.addEventListener('click', preview));
    document.getElementById('ma-close-preview').addEventListener('click', closePreview);
    modal.addEventListener('click', event => { if (event.target === modal) closePreview(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.classList.contains('open')) closePreview(); });
    document.getElementById('ma-print-current').addEventListener('click', () => {
        document.body.classList.add('ma-modul-print-mode');
        window.print();
    });
    window.addEventListener('afterprint', () => document.body.classList.remove('ma-modul-print-mode'));
    document.getElementById('ma-export').addEventListener('click', () => {
        const content = {};
        form.querySelectorAll('textarea[name^="content["]').forEach(el => {
            const match = /^content\[([^\]]+)\]$/.exec(el.name);
            if (match) content[match[1]] = el.value;
        });
        content.dimensi = selections('dimensi');
        content.prinsip = selections('prinsip');
        const payload = {format:'eduxid-modul-ajar-draf-v11',exported_at:new Date().toISOString(),
            title:titleField.value, subject_id:Number(form.elements.namedItem('subject_id').value),
            grade_level:Number(form.elements.namedItem('grade_level').value),
            duration_jp:Number(form.elements.namedItem('duration_jp').value),
            atp_item_id:Number(form.elements.namedItem('atp_item_id').value)||null,
            generation_source:document.getElementById('ma-source').value,content};
        const blob = new Blob([JSON.stringify(payload,null,2)], {type:'application/json;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'Modul-Ajar-' + (titleField.value || 'Draf').replace(/[^a-z0-9-]/gi,'-').slice(0,60) + '.json';
        link.click();
        window.setTimeout(() => URL.revokeObjectURL(url), 1200);
    });
    updateProgress(); showStep(0);
});
</script>
@endif
</x-app-layout>
