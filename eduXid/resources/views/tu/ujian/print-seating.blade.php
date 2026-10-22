<?php
// V21R68: variabel denah dihitung sebelum Blade memproses CSS dan perulangan.
// Tetap berfungsi dengan Controller V21R62 dan V21R67.
$orientation = ($orientation ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
$columns = max(4, min(10, (int) ($room->seat_columns ?? 6)));
$perPage = $columns * ($orientation === 'portrait' ? 6 : 4);
$maxSeat = (int) ($participants->max('seat_number') ?? 0);
$capacity = max(0, (int) ($room->capacity_live ?? 0), $maxSeat);
$pageCount = max(1, (int) ceil($capacity / max(1, $perPage)));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Denah Tempat Duduk - {{ $room->room_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #111827; background: #e5e7eb; }
        .toolbar { position: sticky; top: 0; z-index: 5; padding: 10px 16px; background: #111827; color: white; display: flex; justify-content: space-between; align-items: center; }
        .toolbar button { border: 0; border-radius: 6px; padding: 8px 14px; font-weight: 700; cursor: pointer; }
        .sheet { width: {{ $orientation === 'portrait' ? '210mm' : '297mm' }}; min-height: {{ $orientation === 'portrait' ? '297mm' : '210mm' }}; margin: 10mm auto; padding: 9mm 11mm; background: white; page-break-after: always; break-after: page; }
        .sheet:last-child { page-break-after: auto; break-after: auto; }
        .print-kop { min-height: 22mm; display: flex; align-items: center; justify-content: center; border-bottom: 2px solid #111827; padding-bottom: 2mm; margin-bottom: 2mm; }
        .print-kop-image { width: 100%; max-height: 22mm; object-fit: contain; }
        .print-kop-text { text-align: center; font-size: 9pt; line-height: 1.2; }
        .school-name { font-size: 15pt; font-weight: 800; }
        h1 { font-size: 13pt; margin: 2mm 0 1mm; text-align: center; }
        .subtitle { text-align: center; font-size: 8.5pt; margin-bottom: 2mm; }
        .front { border: 2px solid #111827; background: #f3f4f6; text-align: center; font-size: 8pt; font-weight: 800; padding: 1.7mm; margin: 0 auto 2mm; max-width: 210mm; }
        .proctor { width: 45mm; margin: 0 auto 3mm; border: 1px solid #6b7280; border-radius: 2mm; padding: 1.3mm; text-align: center; font-size: 7pt; font-weight: 800; }
        .seats { display: grid; gap: 1.7mm; align-items: stretch; }
        .seat { height: {{ $orientation === 'portrait' ? '30mm' : '29mm' }}; border: 1.3px solid #374151; border-radius: 2mm; padding: 1mm; text-align: center; display: flex; flex-direction: column; justify-content: flex-start; align-items: center; break-inside: avoid; page-break-inside: avoid; overflow: hidden; }
        .seat.empty { border-style: dashed; color: #9ca3af; justify-content: center; }
        .seat-no { font-size: 6.5pt; font-weight: 800; color: #4b5563; line-height: 1.1; }
        .photo-frame { position: relative; width: {{ $orientation === 'portrait' ? '10mm' : '11mm' }}; height: {{ $orientation === 'portrait' ? '11mm' : '12mm' }}; flex: 0 0 12mm; margin: .6mm 0; border: 1px solid #cbd5e1; border-radius: 1mm; overflow: hidden; background: #f1f5f9; }
        .photo-missing { display: flex; align-items: center; justify-content: center; height: 100%; font-size: 5pt; color: #64748b; }
        .seat-photo { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; object-position: center center; background: #fff; }
        .name { font-size: 7.2pt; font-weight: 800; line-height: 1.12; max-height: 5.7mm; overflow: hidden; overflow-wrap: anywhere; }
        .meta { font-size: 6pt; line-height: 1.14; }
        .footer { margin-top: 3mm; display: flex; justify-content: space-between; gap: 6mm; font-size: 7.5pt; }
        .legend { color: #4b5563; }
        .door { border: 1px solid #6b7280; padding: 1.5mm 4mm; font-weight: 700; align-self: start; white-space: nowrap; }
        @@media print {
            @@page { size: A4 {{ $orientation }}; margin: 0; }
            body { background: white; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .toolbar { display: none; }
            .sheet { width: {{ $orientation === 'portrait' ? '210mm' : '297mm' }}; height: {{ $orientation === 'portrait' ? '297mm' : '210mm' }}; min-height: 0; margin: 0; padding: 9mm 11mm; overflow: hidden; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>Denah Tempat Duduk — {{ $room->room_name }}</div>
        <button type="button" onclick="cetakDenahSesudahFotoSiap()">Cetak</button>
    </div>

    <?php for ($page = 0; $page < $pageCount; $page++): ?>
        <?php
            $fromSeat = $page * $perPage + 1;
            $toSeat = min($capacity, ($page + 1) * $perPage);
        ?>
        <div class="sheet">
            @include('tu.ujian.partials.print-header')

            <h1>DENAH TEMPAT DUDUK PESERTA UJIAN</h1>
            <div class="subtitle">
                <strong>{{ $program->name }}</strong> · Ruang {{ $room->room_name }} ·
                Kapasitas {{ $capacity }} kursi · Terisi {{ $participants->count() }}
                <?php if ($pageCount > 1): ?> · Bagian {{ $page + 1 }} dari {{ $pageCount }} (kursi {{ $fromSeat }}–{{ $toSeat }}) <?php endif; ?>
            </div>

            <div class="front">PAPAN TULIS / DEPAN RUANG</div>
            <div class="proctor">MEJA PENGAWAS</div>

            <div class="seats" style="grid-template-columns: repeat({{ $columns }}, minmax(0, 1fr));">
                <?php for ($seat = $fromSeat; $seat <= $toSeat; $seat++): ?>
                    <?php $participant = $bySeat->get($seat); ?>
                    <div class="seat {{ $participant ? '' : 'empty' }}">
                        <div class="seat-no">KURSI {{ $seat }}</div>
                        <?php if ($participant): ?>
                            <div class="photo-frame">
                                <span class="photo-missing" aria-hidden="true">Tanpa foto</span>
                                <?php if (!empty($participant->foto_url)): ?>
                                    <img class="seat-photo" src="{{ $participant->foto_url }}" alt="Foto {{ $participant->nama }}"
                                         onerror="this.style.display='none'">
                                <?php endif; ?>
                            </div>
                            <div class="name">{{ $participant->nama }}</div>
                            <div class="meta">{{ $participant->nisn }} · {{ $participant->class_name ?: '-' }}</div>
                        <?php else: ?>
                            <div class="name">KOSONG</div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="footer">
                <div class="legend">
                    Nomor kursi mengikuti pengaturan peserta eduXid.
                    <?php if ($pageCount > 1): ?> Lanjutkan ke bagian berikutnya untuk sisa kursi. <?php endif; ?>
                </div>
                <?php if ($page < $pageCount - 1): ?><div class="door">LANJUTAN →</div><?php endif; ?>
            </div>
            <div class="approval-head" style="display:flex;justify-content:flex-end;margin-top:1mm;font-size:7pt;text-align:center;page-break-inside:avoid;">
              <div style="width:48mm;">Mengetahui, Kepala Sekolah
                <?php if (!empty($signatureUrl)): ?><img src="{{ $signatureUrl }}" alt="Tanda tangan kepala sekolah" style="display:block;height:9mm;max-width:38mm;object-fit:contain;margin:0 auto" onerror="this.style.display='none'"><?php else: ?><div style="height:9mm"></div><?php endif; ?>
                <strong>{{ $principal->nama_lengkap ?? $principal->nama ?? $principal->name ?? '___________________' }}</strong>
              </div>
            </div>
        </div>
    <?php endfor; ?>

    <script>
        let sedangMencetak = false;
        async function cetakDenahSesudahFotoSiap() {
            if (sedangMencetak) return;
            sedangMencetak = true;
            // Tunggu gambar yang sedang dimuat; tetap boleh mencetak jika satu foto rusak.
            const imgs = Array.from(document.querySelectorAll('.seat-photo, .print-kop-image'));
            await Promise.all(imgs.map(function (img) {
                if (img.complete) return Promise.resolve();
                return new Promise(function (resolve) {
                    const selesai = function () { resolve(); };
                    img.addEventListener('load', selesai, {once: true});
                    img.addEventListener('error', selesai, {once: true});
                    setTimeout(selesai, 5000);
                });
            }));
            window.print();
            sedangMencetak = false;
        }
        <?php if (request()->query('autoprint') === '1'): ?>
        window.addEventListener('load', function () { cetakDenahSesudahFotoSiap(); }, {once:true});
        <?php endif; ?>
    </script>
</body>
</html>
