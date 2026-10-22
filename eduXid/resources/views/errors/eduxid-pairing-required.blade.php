<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pairing Cloud Diperlukan — eduXid</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 22px; background: #f3f6fb; color: #17233d; font: 16px/1.6 system-ui, sans-serif; }
        .panel { width: min(100%, 570px); background: white; border-radius: 16px; padding: 34px; box-shadow: 0 10px 32px #1b36651c; border: 1px solid #e1e7f0; }
        .icon { width: 51px; height: 51px; display: grid; place-items: center; border-radius: 50%; color: #9b5a03; background: #fff4d8; font-size: 26px; }
        h1 { margin: 18px 0 12px; font-size: 23px; line-height: 1.3; }
        p { margin: 0 0 16px; }
        a { display: inline-block; margin: 6px 8px 0 0; padding: 10px 15px; border-radius: 8px; font-weight: 650; text-decoration: none; background: #1767bf; color: #fff; }
        a.secondary { color: #23476b; background: #eaf1f8; }
        small { display: block; margin-top: 20px; color: #68778c; }
    </style>
</head>
<body>
<main class="panel" role="alert">
    <div class="icon" aria-hidden="true">!</div>
    <h1>Pairing eduXid Cloud Diperlukan</h1>
    <p>{{ $notice }}</p>
    <p>Pairing cukup dilakukan sekali untuk perangkat guru yang digunakan. Setelah berhasil, fitur tetap dapat dipakai secara offline.</p>
    <a href="{{ $waUrl }}" target="_blank" rel="noopener noreferrer">Hubungi Admin Pusat via WhatsApp</a>
    <a class="secondary" href="{{ url('/dashboard') }}">Kembali ke Dasbor</a>
    <small>Kode: EDUXID_CLOUD_PAIRING_REQUIRED (403)</small>
</main>
</body>
</html>
