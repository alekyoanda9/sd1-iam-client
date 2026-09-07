<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Login Gagal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f4f5f7; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 32px 36px; max-width: 420px; text-align: center; }
        .card h1 { font-size: 18px; margin: 0 0 12px; color: #b42318; }
        .card p { color: #444; line-height: 1.5; margin: 0 0 20px; }
        .card a { display: inline-block; padding: 10px 20px; background: #0b3559; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px; }
        .card a:hover { background: #0f477a; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Login Gagal</h1>
        <p>{{ $message }}</p>
        <a href="{{ url('/') }}">Kembali</a>
    </div>
</body>
</html>