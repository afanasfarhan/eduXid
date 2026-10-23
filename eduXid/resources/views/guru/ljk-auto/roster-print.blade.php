<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Cetak LJK per Rombel · eduXid Guru</title>
<style>
  body{margin:0;background:#f4f7fc;color:#172a47;font:14px/1.6 Arial,sans-serif}
  main{max-width:740px;margin:28px auto;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;box-shadow:0 10px 25px #1e3a8a12}
  h1{font-size:24px;margin:0}p{color:#64748b}label{display:grid;gap:7px;font-weight:bold;margin:14px 0}
  select{padding:13px;border:1px solid #cbd5e1;border-radius:10px;background:white;font:inherit}
  button{background:#2563eb;color:white;border:none;border-radius:9px;padding:11px 15px;font:inherit;font-weight:bold;cursor:pointer}
  button:disabled{background:#cbd5e1;color:#475569;cursor:not-allowed}
  .outline{background:white;color:#2563eb;border:1px solid #93c5fd}.note{background:#eff6ff;color:#1e40af;padding:12px;border-radius:10px;margin:14px 0}
  .warning{background:#fffbeb;color:#92400e;padding:11px;border-radius:10px}
</style>
<link rel="stylesheet" href="{{ asset('css/ljk-roster-prefill-v21r177.css') }}?v=V21R178">
</head>
<body><main>
  <h1>🖨️ Cetak LJK Per Rombel</h1>
  <p>Pilih penugasan guru. Setiap siswa memperoleh satu lembar A4-V3 dengan nama, kelas, mapel, dan gelembung NISN otomatis. <b>Hari/Tanggal sengaja kosong untuk ditulis manual.</b></p>
  <form method="GET" action="{{ route('guru.ljk-auto.roster-print') }}">
    <label>Rombel dan mata pelajaran
      <select name="ta" required>
        <option value="">— Pilih penugasan —</option>
        @foreach($assignments as $assignment)
        <option value="{{ (int)$assignment['id'] }}" @selected(($active['id'] ?? null) == $assignment['id'])>{{ $assignment['class_name'] }} · {{ $assignment['subject_name'] }}</option>
        @endforeach
      </select>
    </label>
    <button type="submit">Muat Daftar Siswa</button>
  </form>
  @if($active)
    <div class="note">Rombel: <b>{{ $active['class_name'] }}</b> · Mapel: <b>{{ $active['subject_name'] }}</b> · {{ count($roster) }} siswa</div>
    <button id="eduxid-print-class" type="button" class="outline">Buka Pratinjau / Cetak LJK</button>
    <p class="warning">Cetak di A4, skala 100%, satu sisi. Verifikasi kesesuaian NISN dan jumlah halaman. Data hanya diproses di browser guru.</p>
  @endif
</main>
<script>window.EDUXID_LJK_BG_URL=@json(asset('ljk/LJK-A4-V3-print-bg.png'));</script>
<script src="{{ asset('js/ljk-roster-prefill-v21r177.js') }}?v=V21R178"></script>
@if($active)
<script>
(() => {
  'use strict';
  // Hanya daftar siswa dari kelas yang telah diotorisasi Laravel.
  const assignment = {
    ta_id: @json((int)$active['id']),
    homeroom_name: @json((string)$active['class_name']),
    subject_name: @json((string)$active['subject_name']),
    roster: @json($roster)
  };
  document.getElementById('eduxid-print-class').addEventListener('click', () => {
    window.eduXidLjkRosterPrintV177.openLaptop([assignment]);
  });
})();
</script>
@endif
</body></html>
