<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Login Gagal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f4f5f7; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 32px 36px; max-width: 440px; text-align: center; }
        .card h1 { font-size: 18px; margin: 0 0 12px; color: #b42318; }
        .card p { color: #444; line-height: 1.5; margin: 0 0 20px; }
        .card p.hint { font-size: 12px; color: #888; margin-bottom: 24px; }
        .actions { display: flex; gap: 10px; justify-content: center; }
        .card a { display: inline-block; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .btn-primary { background: #0b3559; color: #fff; }
        .btn-primary:hover { background: #0f477a; }
        .btn-secondary { background: #eee; color: #333; }
        .btn-secondary:hover { background: #e0e0e0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Login Gagal</h1>
        <p>{{ $message }}</p>
        <p class="hint">
            Catatan: kalau Anda masih memiliki sesi aktif di OMI-IAM, tombol
            "Login Ulang" mungkin tidak menampilkan form login lagi dan
            langsung gagal dengan pesan yang sama — ini normal selama akses
            akun Anda ke aplikasi ini belum diaktifkan di sisi IAM.
        </p>
        <div class="actions">
            <a class="btn-primary" href="{{ $loginUrl }}">Login Ulang</a>
            <a class="btn-secondary" href="{{ $homeUrl }}">Kembali ke Beranda</a>
        </div>
    </div>
</body>
</html>