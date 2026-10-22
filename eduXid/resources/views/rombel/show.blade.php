<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Denah Kelas: {{ $rombel->nama }}
        </h2>
    </x-slot>

    <style>
        .student-box {
            user-select: none;
            -webkit-user-select: none;
            touch-action: manipulation;
        }

        .info-trigger {
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }

        .drag-handle {
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            cursor: grab;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        /* V21R91: gagang pemindah berada di luar sudut foto, selalu di atas gambar.
           Tidak mengubah object-fit:contain V21R89 maupun logika simpan posisi. */
        #canvas-denah .student-box .drag-handle {
            top: -8px !important;
            right: -8px !important;
            z-index: 2000 !important;
            width: 30px;
            height: 30px;
            background: #fff;
            color: #4338ca;
            border: 2px solid #6366f1;
            border-radius: 8px;
            box-shadow: 0 2px 7px rgba(0, 0, 0, .25);
            pointer-events: auto !important;
        }
        #canvas-denah .student-box .info-trigger img[alt^="Foto "] {
            pointer-events: none;
        }
        #canvas-denah .student-box .drag-handle:focus-visible {
            outline: 3px solid #4f46e5;
            outline-offset: 2px;
        }

        /* V21R93: referensi 4 pasang meja dalam satu baris.
           Kursi 2-2-2-2 dipisah 3 lorong lebar. HANYA geser visual;
           posisi tersimpan tetap koordinat 110x155 lama. */
        #canvas-denah { min-width: 1230px; background: #fff; }
        #canvas-denah .student-box {
            translate: var(--eduxid-aisle-shift, 0px) 0px;
            z-index: 1;
            background: #1b607f;
            border: 3px solid #092e40;
            border-radius: 2px;
        }
        #canvas-denah .student-box .info-trigger [title="Klik nama untuk melihat profil"],
        #canvas-denah .student-box .info-trigger [title="Klik NISN untuk melihat profil"] {
            color: #fff;
        }
        #canvas-denah .student-box .info-trigger [title="Klik nama untuk melihat profil"] {
            color: #fff;
        }
        .eduxid-denah-guide {
            position: absolute; width: 90px; height: 140px;
            border: 1px dashed #e2e8f0; border-radius: 2px;
            background: #fff; box-sizing: border-box;
            pointer-events: none; z-index: 0;
            display: flex; align-items: flex-end; justify-content: center;
            padding-bottom: 7px; color: #cbd5e1; font-size: 10px;
        }
        /* Lorong tidak digambar: jarak kosong antar pasangan mengikuti gambar. */
        /* Cetak denah kelas dari DOM saat ini, bukan dari berkas foto asal. */
        #eduxid-denah-print { display: none; }
        @@media print {
            @@page { size: A4 landscape; margin: 0; }
            body > :not(#eduxid-denah-print) { display: none !important; }
            #eduxid-denah-print { display: block !important; color: #111827; font-family: Arial, sans-serif; }
            .eduxid-print-page {
                width: 297mm; height: 210mm; padding: 9mm 12mm;
                break-after: page; page-break-after: always;
                box-sizing: border-box; background: #fff; overflow: hidden;
            }
            .eduxid-print-page:last-child { break-after: auto; page-break-after: auto; }
            .eduxid-print-heading { font-weight: 800; font-size: 15pt; text-align: center; margin: 0 0 1mm; }
            .eduxid-print-subtitle { font-size: 9pt; text-align: center; margin: 0 0 2mm; }
            .eduxid-print-front { border: 2px solid #374151; background: #f1f5f9; font-size: 9pt; font-weight: 800; text-align: center; padding: 2mm; margin-bottom: 3mm; }
            .eduxid-print-seat-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)) 11mm repeat(2,minmax(0,1fr)) 11mm repeat(2,minmax(0,1fr)) 11mm repeat(2,minmax(0,1fr)); gap: 2mm; }
            .eduxid-print-aisle { text-align: center; font-weight: 800; font-size: 6pt; border-left: 1px dashed #94a3b8; border-right: 1px dashed #94a3b8; background: #f8fafc; writing-mode: vertical-rl; display: flex; align-items: center; justify-content: center; }
            .eduxid-print-seat { border: 1px solid #64748b; border-radius: 1.7mm; min-width: 0; height: 31mm; padding: 1mm; text-align: center; box-sizing: border-box; overflow: hidden; break-inside: avoid; }
            .eduxid-print-seat.empty { border-style: dashed; color: #94a3b8; }
            .eduxid-print-seat-number { color: #475569; font-weight: 800; font-size: 6.5pt; line-height: 1; }
            .eduxid-print-seat img { display: block; height: 14mm; width: 13mm; object-fit: contain; margin: 1mm auto 0; }
            .eduxid-print-seat-name { font-weight: 700; font-size: 7pt; line-height: 1.13; overflow-wrap: anywhere; }
            .eduxid-print-seat-nisn { font-size: 6.3pt; line-height: 1.1; }
            .eduxid-print-foot { margin-top: 2mm; font-size: 7pt; text-align: center; }
        }

    </style>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            
            <div class="bg-gray-800 text-white p-4 rounded-t-lg text-center mb-4 shadow-md">
                <h3 class="text-lg font-bold">PAPAN TULIS - {{ strtoupper($rombel->nama) }}</h3>
            </div>

            @if(auth()->user()->role != 'admin')
                <div class="mb-4 flex flex-wrap justify-end gap-2">
                    <button type="button" id="btn-cetak-denah" class="bg-blue-600 text-white px-6 py-2 rounded-md shadow-sm hover:bg-blue-700 font-bold transition-colors">
                        🖨 Cetak Denah
                    </button>
                    <button type="button" id="btn-reset-denah" class="bg-red-500 text-white px-6 py-2 rounded-md shadow-sm hover:bg-red-600 font-bold transition-colors">
                        🔄 Reset Denah
                    </button>
                    <button id="btn-simpan-denah" class="bg-green-600 text-white px-6 py-2 rounded-md shadow-sm hover:bg-green-700 font-bold transition-colors">
                        💾 Simpan Denah
                    </button>
                </div>
            @else
                <div class="mb-4 flex flex-wrap justify-end gap-2">
                    <button type="button" id="btn-cetak-denah" class="bg-blue-600 text-white px-6 py-2 rounded-md shadow-sm hover:bg-blue-700 font-bold transition-colors">
                        🖨 Cetak Denah
                    </button>
                    <div class="bg-gray-100 text-gray-500 px-4 py-2 rounded-md font-bold text-sm border border-dashed">
                        🔒 Mode Lihat Denah (Admin)
                    </div>
                </div>
            @endif

            <div class="bg-white p-4 rounded-lg shadow-sm border-2 border-gray-200 relative overflow-auto" 
                 style="min-height: 470px; background: #f8fafc;" 
                 id="canvas-denah" data-rombel="{{ $rombel->id }}">
                
                @php
                    $maxCols = 8; 
                    $startX = 10; 
                    $startY = 10; 
                    $stepX = 110;  
                    $stepY = 155;  
                @endphp

                <?php $studentMedia = app(\App\Services\LocalMediaService::class); ?>
                <?php foreach ($students as $index => $student) { ?>
                    @php
                        // [V21R82] Nama aman pada runtime PHP tanpa mbstring.
                        // Jangan gunakan Str::title(): beberapa implementasi tetap memerlukan mbstring.
                        $namaMentah = (string) ($student->nama ?? '');
                        $namaTampil = function_exists('mb_convert_case')
                            ? mb_convert_case(strtolower($namaMentah), MB_CASE_TITLE, 'UTF-8')
                            : ucwords(strtolower($namaMentah));

                        // [V21R82] Media opsional tidak boleh mematikan satu halaman profil.
                        $fotoUrl = null;
                        if (is_string($student->foto_path ?? null) && trim($student->foto_path) !== '') {
                            try {
                                if ($studentMedia->exists($student->foto_path)) {
                                    $fotoUrl = $studentMedia->url($student->foto_path);
                                }
                            } catch (\Throwable $mediaException) {
                                $fotoUrl = null;
                            }
                        }
                        $row = floor($index / $maxCols);
                        $col = $index % $maxCols;
                        $defaultX = $startX + ($col * $stepX);
                        $defaultY = $startY + ($row * $stepY);
                        $finalX = $student->pos_x ?? $defaultX;
                        $finalY = $student->pos_y ?? $defaultY;
                        
                        $sakit = $student->sakit ?? 0;
                        $izin = $student->izin ?? 0;
                        $alpa = $student->alpa ?? 0;
                        
                        $nilai_diagnostik = $student->nilai_diagnostik ? round($student->nilai_diagnostik) : '-';
                        $nilai_formatif = $student->nilai_formatif ? round($student->nilai_formatif) : '-';
                        $nilai_sumatif = $student->nilai_sumatif ? round($student->nilai_sumatif) : '-';
                        
                        $rataSikap = $student->rata_sikap ? round($student->rata_sikap) : 4;
                        $bintang_html = '';
                        for($i=1; $i<=5; $i++) {
                            $bintang_html .= $i <= $rataSikap ? '★' : '☆';
                        }
                    @endphp
                    
                    <div class="student-box absolute flex flex-col items-center justify-center bg-indigo-50 border-2 border-indigo-300 rounded-lg p-1 w-[90px] h-[140px] shadow-sm hover:border-indigo-500 transition-colors cursor-default" 
                         id="meja-{{ $student->enrollment_id }}" 
                         data-id="{{ $student->enrollment_id }}"
                         style="left: {{ $finalX }}px; top: {{ $finalY }}px;">

                        @if(auth()->user()->role != 'admin')
                            <button type="button"
                                    class="drag-handle absolute top-0.5 right-0.5 z-20 w-6 h-6 rounded bg-white/90 border border-indigo-200 text-indigo-500 text-xs font-bold leading-none flex items-center justify-center shadow-sm"
                                    title="Geser posisi murid"
                                    aria-label="Geser posisi {{ $student->nama }}">
                                ↕
                            </button>
                        @endif
                        
                        <div class="info-trigger flex flex-col items-center cursor-pointer w-full"
                             role="button"
                             tabindex="0"
                             title="Klik untuk lihat profil murid" 
                             data-id="{{ $student->id }}" 
                             data-nisn="{{ $student->nisn ?? '-' }}"
                             data-nama="{{ $namaTampil }}" 
                             data-jk="{{ $student->jenis_kelamin }}" 
                             data-sakit="{{ $sakit }}" data-izin="{{ $izin }}" data-alpa="{{ $alpa }}" 
                             data-diagnostik="{{ $nilai_diagnostik }}" data-formatif="{{ $nilai_formatif }}" data-sumatif="{{ $nilai_sumatif }}"
                             data-foto-url="{{ $fotoUrl }}"
                             data-rata_sikap="{{ $rataSikap }}"
                             data-avg_d1="{{ $student->avg_d1 ?? 4 }}" data-avg_d2="{{ $student->avg_d2 ?? 4 }}" data-avg_d3="{{ $student->avg_d3 ?? 4 }}" data-avg_d4="{{ $student->avg_d4 ?? 4 }}"
                             data-avg_d5="{{ $student->avg_d5 ?? 4 }}" data-avg_d6="{{ $student->avg_d6 ?? 4 }}" data-avg_d7="{{ $student->avg_d7 ?? 4 }}" data-avg_d8="{{ $student->avg_d8 ?? 4 }}">
                            
                            @if($fotoUrl)
                                <div class="relative w-12 h-16 mb-1">
                                    <img src="{{ $fotoUrl }}" class="w-full h-full rounded-md object-cover border border-gray-300" alt="Foto {{ $student->nama }}" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                                    <span hidden class="absolute inset-0 rounded-md bg-gray-100 text-[10px] text-gray-600 items-center justify-center text-center">Tanpa foto</span>
                                </div>
                            @else
                                @php
                                    $emoji = $student->jenis_kelamin == 'L' ? '👦' : ($student->jenis_kelamin == 'P' ? '👧' : '🧑');
                                @endphp
                                <div class="w-12 h-16 rounded-md mb-1 bg-blue-100 flex items-center justify-center text-4xl">
                                    {{ $emoji }}
                                </div>
                            @endif

                            <div class="text-center text-[11px] font-bold text-gray-800 leading-tight line-clamp-2 w-full px-1" title="Klik nama untuk melihat profil">
                                {{ $namaTampil }}
                            </div>
                            <div class="text-center text-[10px] text-indigo-700 leading-tight w-full px-0.5 truncate" title="Klik NISN untuk melihat profil">{{ $student->nisn ?? '-' }}</div>
                            <div class="text-yellow-400 text-sm mt-0.5">{{ $bintang_html }}</div>
                        </div>
                    </div>
                <?php } // [V21R85] Akhir loop murid, tidak memakai endforeach Blade. ?>

            </div>
        </div>
    </div>

    {{-- [V21R87] Modal yang sama kini dimuat sekali melalui navigasi global. --}}

    <script>
        let isDragging = false;

        @if(auth()->user()->role != 'admin')
        document.addEventListener('DOMContentLoaded', function() {
            const handles = document.querySelectorAll('.drag-handle');
            const canvas = document.getElementById('canvas-denah');

            handles.forEach(function(handle) {
                let box = null;
                let pointerId = null;
                let startClientX = 0;
                let startClientY = 0;
                let startLeft = 0;
                let startTop = 0;
                let deltaX = 0;
                let deltaY = 0;

                function cleanupDrag() {
                    if (!box) {
                        return;
                    }

                    box.style.transform = '';
                    box.style.zIndex = 1;
                    box.classList.remove('ring-2', 'ring-indigo-400');
                    box.setAttribute('data-x', 0);
                    box.setAttribute('data-y', 0);
                }

                function finishDrag(event) {
                    if (!box || pointerId === null) {
                        return;
                    }

                    if (event && event.pointerId !== pointerId) {
                        return;
                    }

                    let newLeft = startLeft + deltaX;
                    let newTop = startTop + deltaY;

                    // Cari kolom dari posisi VISUAL agar geser melewati lorong
                    // tidak melompati dua kursi. Simpan tetap koordinat lama.
                    const initialCol = Math.max(0, Math.min(7, Math.round((startLeft - 10) / 110)));
                    const visualX = eduxidVisualSeatX(initialCol) + deltaX;
                    let nearestCol = 0;
                    for (let candidate = 1; candidate < 8; candidate++) {
                        const x = eduxidVisualSeatX(candidate);
                        const current = eduxidVisualSeatX(nearestCol);
                        if (Math.abs(visualX - x) < Math.abs(visualX - current)) nearestCol = candidate;
                    }
                    let snappedLeft = 10 + nearestCol * 110;
                    let snappedTop = Math.round((newTop - 10) / 155) * 155 + 10;

                    if (canvas) {
                        const maxLeft = Math.max(10, canvas.scrollWidth - box.offsetWidth - 10);
                        const maxTop = Math.max(10, canvas.scrollHeight - box.offsetHeight - 10);

                        snappedLeft = Math.max(10, Math.min(snappedLeft, maxLeft));
                        snappedTop = Math.max(10, Math.min(snappedTop, maxTop));
                    }

                    const occupied = Array.from(document.querySelectorAll('.student-box')).find(function(other) {
                        if (other === box) {
                            return false;
                        }

                        const otherLeft = parseFloat(other.style.left) || 0;
                        const otherTop = parseFloat(other.style.top) || 0;

                        return Math.abs(otherLeft - snappedLeft) < 2
                            && Math.abs(otherTop - snappedTop) < 2;
                    });

                    if (occupied) {
                        occupied.style.left = startLeft + 'px';
                        occupied.style.top = startTop + 'px';
                        occupied.style.transform = '';
                        eduxidUpdateAisleShift(occupied);
                        occupied.setAttribute('data-x', 0);
                        occupied.setAttribute('data-y', 0);
                    }

                    box.style.left = snappedLeft + 'px';
                    box.style.top = snappedTop + 'px';
                    eduxidUpdateAisleShift(box);
                    eduxidBuildGridGuides();

                    cleanupDrag();

                    try {
                        if (handle.hasPointerCapture(pointerId)) {
                            handle.releasePointerCapture(pointerId);
                        }
                    } catch (e) {
                    }

                    pointerId = null;
                    deltaX = 0;
                    deltaY = 0;
                    box = null;

                    window.setTimeout(function() {
                        isDragging = false;
                    }, 120);
                }

                handle.addEventListener('pointerdown', function(event) {
                    if (event.button !== undefined && event.button !== 0) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    box = handle.closest('.student-box');

                    if (!box) {
                        return;
                    }

                    pointerId = event.pointerId;
                    startClientX = event.clientX;
                    startClientY = event.clientY;
                    startLeft = parseFloat(box.style.left) || 0;
                    startTop = parseFloat(box.style.top) || 0;
                    deltaX = 0;
                    deltaY = 0;

                    isDragging = true;
                    box.style.zIndex = 1000;
                    box.classList.add('ring-2', 'ring-indigo-400');

                    try {
                        handle.setPointerCapture(pointerId);
                    } catch (e) {
                    }
                });

                handle.addEventListener('pointermove', function(event) {
                    if (!box || pointerId === null || event.pointerId !== pointerId) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();

                    deltaX = event.clientX - startClientX;
                    deltaY = event.clientY - startClientY;

                    box.style.transform = 'translate(' + deltaX + 'px, ' + deltaY + 'px)';
                    box.setAttribute('data-x', deltaX);
                    box.setAttribute('data-y', deltaY);
                });

                handle.addEventListener('pointerup', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    finishDrag(event);
                });

                handle.addEventListener('pointercancel', function(event) {
                    finishDrag(event);
                });

                handle.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                });
            });
        });
        @endif


        // V21R93 — 4 pasangan kursi (2-2-2-2) dengan 3 lorong kosong.
        // Koordinat database tetap 10+110*col dan 10+155*row.
        const eduxidAisleWidth = 110;
        const eduxidLogicalCols = 8;
        const eduxidVisualSeatX = (col) => 10 + col * 110 + Math.floor(col / 2) * eduxidAisleWidth;
        function eduxidSlotIndex(box) {
            const left = Number.parseFloat(box.style.left) || 10;
            const top = Number.parseFloat(box.style.top) || 10;
            return {
                col: Math.max(0, Math.min(7, Math.round((left - 10) / 110))),
                row: Math.max(0, Math.round((top - 10) / 155))
            };
        }
        function eduxidUpdateAisleShift(box) {
            const col = eduxidSlotIndex(box).col;
            box.style.setProperty('--eduxid-aisle-shift', (Math.floor(col / 2) * eduxidAisleWidth) + 'px');
        }
        function eduxidBuildGridGuides() {
            const board = document.getElementById('canvas-denah');
            if (!board) return;
            board.querySelectorAll('.eduxid-denah-guide,.eduxid-denah-aisle,.eduxid-denah-row-label').forEach(el => el.remove());
            const boxes = Array.from(board.querySelectorAll('.student-box'));
            boxes.forEach(eduxidUpdateAisleShift);
            // Empat baris sesuai acuan bila jumlah murid <= 32.
            // Denah dengan lebih banyak murid akan bertambah baris otomatis.
            const rows = Math.max(4, Math.ceil(boxes.length / eduxidLogicalCols), ...boxes.map(b => eduxidSlotIndex(b).row + 1));
            board.style.minHeight = Math.max(640, 25 + rows * 155) + 'px';
            for (let row = 0; row < rows; row++) {
                for (let col = 0; col < eduxidLogicalCols; col++) {
                    const guide = document.createElement('div');
                    guide.className = 'eduxid-denah-guide';
                    guide.style.left = eduxidVisualSeatX(col) + 'px';
                    guide.style.top = (10 + row * 155) + 'px';
                    guide.textContent = 'KURSI ' + String(row * 8 + col + 1).padStart(2, '0');
                    board.appendChild(guide);
                }
            }
        }
        eduxidBuildGridGuides();
        // Tampilan cetak memakai kartu yang ada di layar, termasuk perubahan
        // posisi yang belum disimpan; tidak mengubah/mengirim data apa pun.
        const eduxidBtnCetak = document.getElementById('btn-cetak-denah');
        if (eduxidBtnCetak) eduxidBtnCetak.addEventListener('click', async function () {
            const board = document.getElementById('canvas-denah');
            if (!board) return;
            let stage = document.getElementById('eduxid-denah-print');
            if (!stage) {
                stage = document.createElement('div');
                stage.id = 'eduxid-denah-print';
                document.body.appendChild(stage);
            }
            stage.replaceChildren();
            const cards = Array.from(board.querySelectorAll('.student-box'));
            const byPosition = new Map();
            let maxRow = -1;
            cards.forEach(card => {
                const slot = eduxidSlotIndex(card);
                maxRow = Math.max(maxRow, slot.row);
                const key = slot.row + ':' + slot.col;
                if (!byPosition.has(key)) byPosition.set(key, card);
            });
            const rows = Math.max(1, maxRow + 1);
            const pageCount = Math.ceil(rows / 4);
            const classroom = @json($rombel->nama);
            const pictures = [];
            function elt(tag, cls, content) {
                const el = document.createElement(tag);
                if (cls) el.className = cls;
                if (content !== undefined && content !== null) el.textContent = String(content);
                return el;
            }
            for (let pageNo = 0; pageNo < pageCount; pageNo++) {
                const page = elt('section', 'eduxid-print-page');
                page.appendChild(elt('h1', 'eduxid-print-heading', 'DENAH TEMPAT DUDUK KELAS'));
                page.appendChild(elt('div', 'eduxid-print-subtitle', classroom + ' · ' + cards.length + ' murid' + (pageCount > 1 ? ' · Halaman ' + (pageNo+1) + '/' + pageCount : '')));
                page.appendChild(elt('div', 'eduxid-print-front', 'PAPAN TULIS / DEPAN KELAS'));
                const grid = elt('div', 'eduxid-print-seat-grid');
                for (let line = 0; line < 4; line++) {
                    const row = pageNo * 4 + line;
                    // 11 trek cetak: 2 kursi + lorong + 2 + lorong + 2 + lorong + 2.
                    const visibleTracks = [0, 1, null, 2, 3, null, 4, 5, null, 6, 7];
                    for (const logicalCol of visibleTracks) {
                        if (logicalCol === null) {
                            grid.appendChild(elt('div', 'eduxid-print-aisle', ''));
                            continue;
                        }
                        const card = row < rows ? byPosition.get(row + ':' + logicalCol) : null;
                        const seat = elt('div', 'eduxid-print-seat' + (card ? '' : ' empty'));
                        const number = row * 8 + logicalCol + 1;
                        seat.appendChild(elt('div', 'eduxid-print-seat-number', 'KURSI ' + String(number).padStart(2, '0')));
                        if (card) {
                            const data = card.querySelector('.info-trigger')?.dataset;
                            const photo = data?.fotoUrl;
                            if (photo) {
                                const img = document.createElement('img');
                                img.src = photo;
                                img.alt = 'Foto murid';
                                seat.appendChild(img);
                                pictures.push(img);
                            } else seat.appendChild(elt('div', '', 'Tanpa foto'));
                            seat.appendChild(elt('div', 'eduxid-print-seat-name', data?.nama || '—'));
                            seat.appendChild(elt('div', 'eduxid-print-seat-nisn', data?.nisn || '—'));
                        } else seat.appendChild(elt('div', 'eduxid-print-seat-name', 'KOSONG'));
                        grid.appendChild(seat);
                    }
                }
                page.appendChild(grid);
                page.appendChild(elt('div', 'eduxid-print-foot', 'Denah mengikuti posisi kartu yang tampak saat tombol Cetak Denah ditekan.'));
                stage.appendChild(page);
            }
            // Hindari cetak foto kosong akibat gambar yang belum selesai dimuat.
            await Promise.all(pictures.map(img => img.complete ? Promise.resolve() : new Promise(resolve => {
                const done = () => resolve();
                img.addEventListener('load', done, {once:true});
                img.addEventListener('error', done, {once:true});
                window.setTimeout(done, 4000);
            })));
            window.print();
        });

        // Logika Simpan Denah
        let btnSimpan = document.getElementById('btn-simpan-denah');
        if(btnSimpan) {
            btnSimpan.addEventListener('click', function() {
                let mejaList = document.querySelectorAll('.student-box');
                let promises = [];
                let btn = this; 
                btn.innerText = 'Menyimpan...'; 
                btn.disabled = true;
                
                mejaList.forEach(function(meja) {
                    let id = meja.getAttribute('data-id');
                    let x = parseInt(meja.style.left) || 0;
                    let y = parseInt(meja.style.top) || 0;

                    promises.push(fetch('/api/seating/update', {
                        method: 'POST', 
                        headers: { 
                            'Content-Type': 'application/json', 
                            'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                            'Accept': 'application/json' 
                        },
                        body: JSON.stringify({ enrollment_id: id, pos_x: x, pos_y: y })
                    }).then(res => { if(!res.ok) throw new Error('Gagal'); return res.json(); }));
                });
                
                Promise.all(promises).then(function() { 
                    alert('✅ Denah kelas berhasil disimpan!'); 
                    location.reload(); 
                }).catch(function(error) { 
                    alert('❌ Terjadi kesalahan: ' + error.message); 
                    btn.innerText = '💾 Simpan Denah'; 
                    btn.disabled = false; 
                });
            });
        }

        // Logika Reset Denah
        let btnReset = document.getElementById('btn-reset-denah');
        if(btnReset) {
            btnReset.addEventListener('click', function() {
                if (confirm('Apakah Anda yakin ingin mereset posisi denah ke 8 kolom (2-2-2-2)? Posisi tersimpan akan dihapus.')) {
                    let rombelId = document.getElementById('canvas-denah').dataset.rombel;
                    fetch('/api/seating/reset', {
                        method: 'POST', 
                        headers: { 
                            'Content-Type': 'application/json', 
                            'X-CSRF-TOKEN': '{{ csrf_token() }}', 
                            'Accept': 'application/json' 
                        },
                        body: JSON.stringify({ homeroom_id: rombelId })
                    }).then(res => res.json()).then(data => { 
                        alert('✅ Denah berhasil direset!'); 
                        location.reload(); 
                    }).catch(err => alert('❌ Gagal mereset: ' + err.message));
                }
            });
        }

        // Logika Modal Siswa
        const modal = document.getElementById('modal-siswa');
        const triggers = document.querySelectorAll('.info-trigger');

        function openStudentModal(trigger) {
            if (!modal || !trigger) {
                return;
            }

            document.getElementById('modal-foto-container').innerHTML = '';

            const fotoUrl = trigger.dataset.fotoUrl;
            const jk = trigger.dataset.jk;
            const fotoContainer = document.getElementById('modal-foto-container');
            const fallbackPhoto = () => {
                fotoContainer.replaceChildren();
                const empty = document.createElement('div');
                empty.style.cssText = 'width: 96px; height: 110px;';
                empty.className = 'rounded-md shadow-sm bg-gray-100 flex items-center justify-center text-xs text-gray-500';
                empty.textContent = 'Tanpa foto';
                fotoContainer.appendChild(empty);
            };
            if (fotoUrl) {
                const image = document.createElement('img');
                image.src = fotoUrl;
                image.alt = 'Foto murid';
                image.style.cssText = 'width: 96px; height: 110px;';
                image.className = 'rounded-md shadow-md object-cover';
                image.addEventListener('error', fallbackPhoto, {once: true});
                fotoContainer.appendChild(image);
            } else {
                fallbackPhoto();
            }

            document.getElementById('modal-nama').innerText = trigger.dataset.nama;
            document.getElementById('modal-nisn').innerText = '(' + trigger.dataset.nisn + ')';
            document.getElementById('modal-jk').innerText = trigger.dataset.jk == 'L'
                ? 'Laki-laki'
                : (trigger.dataset.jk == 'P' ? 'Perempuan' : '-');

            document.getElementById('modal-sakit').innerText = trigger.dataset.sakit;
            document.getElementById('modal-izin').innerText = trigger.dataset.izin;
            document.getElementById('modal-alpa').innerText = trigger.dataset.alpa;

            document.getElementById('modal-diagnostik').innerText = trigger.dataset.diagnostik;
            document.getElementById('modal-formatif').innerText = trigger.dataset.formatif;
            document.getElementById('modal-sumatif').innerText = trigger.dataset.sumatif;

            for (let i = 1; i <= 8; i++) {
                let val = parseInt(trigger.dataset['avg_d' + i]) || 4;
                let starsHtml = '';

                for (let j = 1; j <= 5; j++) {
                    starsHtml += `<span class="text-base ${j <= val ? 'text-yellow-400' : 'text-gray-300'}">★</span>`;
                }

                let container = document.getElementById(`modal-d${i}`);
                if (container) {
                    container.innerHTML = starsHtml;
                }
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        triggers.forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openStudentModal(this);
            });

            trigger.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    openStudentModal(this);
                }
            });
        });

        let closeModalBtn = document.getElementById('close-modal');
        if(closeModalBtn) {
            closeModalBtn.addEventListener('click', () => { 
                modal.classList.add('hidden'); 
                modal.classList.remove('flex'); 
            });
        }
        
        if(modal) {
            modal.addEventListener('click', (e) => { 
                if (e.target === modal) { 
                    modal.classList.add('hidden'); 
                    modal.classList.remove('flex'); 
                } 
            });
        }
    </script>
</x-app-layout>