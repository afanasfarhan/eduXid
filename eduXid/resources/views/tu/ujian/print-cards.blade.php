@php($orientation = ($orientation ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait')
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kartu Peserta Ujian — {{ $program->name }}</title>
<style>
* {box-sizing:border-box}
body {margin:0; font-family:Arial,Helvetica,sans-serif; color:#111827;background:#e5e7eb}
.toolbar {position:sticky; top:0;z-index:4;padding:10px 16px;background:#111827;color:white;display:flex;justify-content:space-between;align-items:center}
.toolbar button{padding:8px 14px;border:0;border-radius:5px;font-weight:bold;cursor:pointer}
.sheet{width:{{ $orientation==='portrait'?'210mm':'297mm' }};height:{{ $orientation==='portrait'?'297mm':'210mm' }}; margin:10mm auto;padding:6mm; background:#fff;display:grid; grid-template-columns:repeat({{ $orientation==='portrait'?2:4 }},minmax(0,1fr)); grid-template-rows:repeat({{ $orientation==='portrait'?4:2 }},minmax(0,1fr));gap:2mm;break-after:page;page-break-after:always;overflow:hidden}
.sheet:last-child{break-after:auto;page-break-after:auto}
.card{border:1px solid #374151; padding:2mm;overflow:hidden;display:flex;flex-direction:column;min-width:0;break-inside:avoid;page-break-inside:avoid}
.print-kop{height:10mm;min-height:10mm;display:flex;align-items:center;justify-content:center;border-bottom:1px solid #374151; padding-bottom:1mm;margin-bottom:1mm;overflow:hidden}
.print-kop-image{height:9mm;max-height:9mm;width:100%;object-fit:contain}
.print-kop-text{text-align:center;font-size:5.8pt;line-height:1.1}
.school-name{font-weight:800;font-size:7pt}
.card h2{text-align:center;font-size:7.8pt;line-height:1.1;margin:1mm 0}
.program{text-align:center;font-size:6pt;margin:0 0 1mm}
.identity-wrap{display:flex;gap:1.6mm;align-items:flex-start;min-height:{{ $orientation==='portrait'?'23mm':'25mm' }};margin-bottom:1mm}
.candidate-photo{flex:0 0 {{ $orientation==='portrait'?'16mm':'15mm' }};width:{{ $orientation==='portrait'?'16mm':'15mm' }};height:{{ $orientation==='portrait'?'20mm':'19mm' }};border:1px solid #9ca3af;overflow:hidden;background:#f8fafc}
.candidate-photo img{width:100%;height:100%;object-fit:contain;object-position:center;background:#fff}
.photo-missing{text-align:center;font-size:5pt;padding-top:7mm;color:#6b7280}
.identity{flex:1;min-width:0;font-size:6.2pt;line-height:1.3;overflow-wrap:anywhere}
.identity .row{display:grid;grid-template-columns:25% 75%;gap:1mm;margin-bottom:.6mm}
.identity .value{font-weight:700}
.schedule{border-collapse:collapse;width:100%;font-size:5.8pt;margin-top:1mm}
.schedule td,.schedule th{border:1px solid #9ca3af;padding:.5mm;line-height:1.2}
.sign{margin-top:auto;margin-left:auto;text-align:center;min-height:9mm;width:{{ $orientation==='portrait'?'38mm':'32mm' }};font-size:5.4pt;line-height:1.05}
.sign img{height:5mm;max-width:26mm;object-fit:contain;display:block;margin:0 auto}
.small{font-size:5.3pt;color:#4b5563;margin:1mm 0 0}
@media print{
 @page{size:A4 {{ $orientation }};margin:0}
 body{background:#fff;print-color-adjust:exact;-webkit-print-color-adjust:exact}
 .toolbar{display:none}
 .sheet{margin:0;box-shadow:none}
}
</style>
</head><body>
<div class="toolbar"><span>Kartu Peserta — 8 kartu per A4 {{ ucfirst($orientation) }}</span><button type="button" onclick="printAfterPhotos()">Cetak</button></div>
@forelse($participants->chunk(8) as $pageParticipants)
 <div class="sheet">
 @foreach($pageParticipants as $participant)
 <div class="card">
  @include('tu.ujian.partials.print-header')
  <h2>KARTU PESERTA UJIAN</h2>
  <div class="program">{{ $program->name }}</div>
  <div class="identity-wrap">
   <div class="identity">
    <div class="row"><span>No.</span><span class="value">{{ $participant->nisn }}</span></div>
    <div class="row"><span>Nama</span><span class="value">{{ $participant->nama }}</span></div>
    <div class="row"><span>NISN</span><span>{{ $participant->nisn }}</span></div>
    <div class="row"><span>Kelas</span><span>{{ $participant->class_name ?: '-' }}</span></div>
    <div class="row"><span>Ruang</span><span>{{ $participant->room_name }}</span></div>
   </div>
   <div class="candidate-photo">
    @if(!empty($participant->foto_url))<img src="{{ $participant->foto_url }}" alt="Foto {{ $participant->nama }}" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
    <div class="photo-missing" hidden>Tanpa foto</div>
    @else<div class="photo-missing">Tanpa foto</div>@endif
   </div>
  </div>
  @if($schedules->isNotEmpty())
    <table class="schedule"><tbody>
    @foreach($schedules->take(3) as $schedule)
      <tr><td>{{ \Illuminate\Support\Carbon::parse($schedule->exam_date)->format('d/m') }}</td><td>{{ $schedule->title }}</td><td>{{ substr($schedule->start_time,0,5) }}</td></tr>
    @endforeach
    </tbody></table>
    @if($schedules->count()>3)<div class="small">Jadwal lengkap: lihat daftar ujian sekolah</div>@endif
  @endif
  <div class="sign">Kepala Sekolah
  @if(!empty($signatureUrl))<img src="{{ $signatureUrl }}" alt="Tanda tangan" onerror="this.style.display='none'">@else<div style="height:5mm"></div>@endif
  <strong>{{ $principal->nama_lengkap ?? $principal->nama ?? $principal->name ?? '________________' }}</strong>
  </div>
 </div>
 @endforeach
 </div>
@empty
 <div style="padding:20mm;text-align:center">Belum ada peserta ujian.</div>
@endforelse
<script>
async function printAfterPhotos(){
 const imgs=[...document.querySelectorAll('.sheet img')];
 await Promise.race([Promise.all(imgs.map(img=>{
   if(img.complete)return Promise.resolve();
   return new Promise(resolve=>{img.addEventListener('load',resolve,{once:true});img.addEventListener('error',resolve,{once:true})});
 })),new Promise(resolve=>setTimeout(resolve,12000))]);
 window.print();
}
</script>
</body></html>
