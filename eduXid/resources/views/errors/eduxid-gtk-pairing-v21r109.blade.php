{{-- V21R109: ditampilkan oleh guard GTK dengan HTTP 403 --}}
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pairing diperlukan — eduXid</title>
<style>
*{box-sizing:border-box}body{margin:0;font:16px system-ui,-apple-system,'Segoe UI',sans-serif;background:#eff6ff;color:#172554}
.backdrop{min-height:100dvh;display:flex;justify-content:center;align-items:center;padding:24px;background:#0f172a90}
.dialog{background:white;max-width:520px;width:100%;padding:27px;border-radius:19px;box-shadow:0 22px 50px #0f172a45;text-align:center}
.icon{margin:auto auto 12px;border-radius:50%;background:#fef3c7;color:#92400e;width:58px;height:58px;display:grid;place-items:center;font-size:29px}
h1{font-size:23px;margin:0 0 12px}p{line-height:1.7;margin:0 0 22px}
.actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}a{display:inline-block;text-decoration:none;border-radius:10px;padding:12px 16px;font-weight:650;background:#1d4ed8;color:#fff}a.alt{background:#e2e8f0;color:#1e293b}
</style></head><body><div class="backdrop"><div class="dialog" role="dialog" aria-modal="true" aria-labelledby="title">
<div class="icon">!</div><h1 id="title">Pairing eduXid diperlukan</h1>
<p>{{ $message }}</p><div class="actions"><a href="{{ route('guru.pengaturan') }}">Buka Pengaturan</a>
<a class="alt" href="{{ route('dashboard') }}">Kembali ke Dasbor</a>
<a class="alt" href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer">Hubungi WhatsApp</a></div>
</div></div></body></html>
