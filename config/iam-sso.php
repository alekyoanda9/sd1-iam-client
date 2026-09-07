<?php

/*
|--------------------------------------------------------------------------
| OMI-IAM SSO Client — Konfigurasi
|--------------------------------------------------------------------------
|
| Semua nilai di sini spesifik untuk APLIKASI KLIEN (mis. IAS, OMIHO,
| CMS-ULOK) yang menyambung ke OMI-IAM sebagai Identity Provider.
| Jangan bingung dengan config/iam.php di dalam project OMI-IAM sendiri —
| itu punya IAM, ini punya client app.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Alamat OMI-IAM
    |--------------------------------------------------------------------------
    */
    'base_url' => rtrim(env('IAM_SSO_BASE_URL', 'http://127.0.0.1:8000'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Kredensial OAuth2 client app ini
    |--------------------------------------------------------------------------
    |
    | Didapat dari menu "Clients" di OMI-IAM saat aplikasi ini didaftarkan.
    | `grant` menentukan alur yang dipakai:
    |
    |   authorization_code (disarankan, default) — browser diarahkan ke
    |     halaman login OMI-IAM (mendukung LOCAL + ESS/NIK Indomaret,
    |     halaman "menunggu aktivasi", pemilihan sumber kredensial, dsb
    |     tanpa perlu kode tambahan di client app).
    |
    |   password — client app punya form login sendiri dan mengirim
    |     username/password langsung ke OMI-IAM lewat /oauth/token.
    |     PENTING: pada kondisi OMI-IAM saat ini, jalur ini HANYA
    |     memvalidasi akun LOCAL (username/password yang dibuat di IAM).
    |     User dengan kredensial ESS (NIK Indomaret) tidak akan berhasil
    |     lewat jalur ini selama `User::validateForPassportPasswordGrant()`
    |     belum disambungkan ke AuthenticationManager di sisi OMI-IAM.
    |     Lihat README bagian "Password Grant" sebelum memakai mode ini.
    |
    */
    'client_id' => env('IAM_SSO_CLIENT_ID'),
    'client_secret' => env('IAM_SSO_CLIENT_SECRET'),
    'grant' => env('IAM_SSO_GRANT', 'authorization_code'),
    'scope' => env('IAM_SSO_SCOPE', ''),

    /*
    |--------------------------------------------------------------------------
    | Rute bawaan paket
    |--------------------------------------------------------------------------
    |
    | Paket ini mendaftarkan rute redirect/callback/logout secara otomatis.
    | Set `routes.enabled` ke false bila ingin mendefinisikan rute itu
    | sendiri (controller tetap tersedia untuk dipakai manual).
    |
    */
    'routes' => [
        'enabled' => env('IAM_SSO_ROUTES_ENABLED', true),
        'prefix' => env('IAM_SSO_ROUTES_PREFIX', 'iam'),
        'middleware' => ['web'],

        // Nama rute yang dipanggil middleware saat user belum login.
        'login_route' => env('IAM_SSO_LOGIN_ROUTE', 'iam.redirect'),

        // Ke mana user diarahkan setelah login berhasil bila tidak ada
        // "intended url" tersimpan (mis. akses langsung ke halaman login).
        'home_route' => env('IAM_SSO_HOME_ROUTE', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect URI OAuth
    |--------------------------------------------------------------------------
    |
    | Kosongkan untuk memakai URL absolut rute callback bawaan paket
    | (route('iam.callback')). Isi manual bila callback di-hosting di
    | path/domain berbeda dari APP_URL (mis. di balik reverse proxy).
    |
    */
    'redirect_uri' => env('IAM_SSO_REDIRECT_URI'),

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    |
    | Setelah logout lokal, browser diarahkan ke halaman logout OMI-IAM
    | (supaya sesi SSO ikut berakhir), lalu OMI-IAM mengembalikan browser
    | ke sini. Host tujuan balik ini HARUS terdaftar di OMI-IAM
    | (oauth_client_details.simulation_url/production_url) atau di
    | IAM_ALLOWED_LOGOUT_HOSTS pada .env OMI-IAM, kalau tidak permintaan
    | redirect-nya ditolak dan user cuma berhenti di halaman login IAM.
    |
    */
    'logout' => [
        // Redirect ke /logout milik OMI-IAM dulu (SSO logout) sebelum
        // kembali ke client app. Set false untuk logout lokal saja
        // (sesi OMI-IAM di browser tetap aktif untuk app lain).
        'sso_logout' => env('IAM_SSO_LOGOUT_REMOTE', true),
        'redirect_back_to' => env('IAM_SSO_LOGOUT_REDIRECT_BACK'), // null = URL('/') aplikasi ini
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard & session
    |--------------------------------------------------------------------------
    */
    'guard' => [
        // Nama guard yang didaftarkan paket ini lewat Auth::extend().
        // Tambahkan manual di config/auth.php aplikasi:
        //   'guards' => ['iam' => ['driver' => 'iam']]
        // Tidak perlu entri "provider" — guard ini tidak membaca tabel
        // user lokal, seluruh datanya berasal dari sesi OMI-IAM.
        'name' => 'iam',
    ],

    // Prefix seluruh key session yang dipakai paket ini, supaya tidak
    // bentrok dengan session key aplikasi yang sudah ada.
    'session_prefix' => env('IAM_SSO_SESSION_PREFIX', '_iam_sso'),

    /*
    |--------------------------------------------------------------------------
    | Cache menu & permission
    |--------------------------------------------------------------------------
    |
    | Mengikuti anjuran API-SDK-CONTRACT.md: revalidasi berkala, bukan
    | tiap request. `store` boleh diisi nama cache store lain (redis, dsb)
    | agar tidak numpuk di session pada aplikasi dengan banyak worker.
    */
    'access_cache' => [
        'ttl' => (int) env('IAM_SSO_ACCESS_TTL', 300), // detik, samakan dgn IAM_ACCESS_CACHE_TTL di IAM
        'store' => env('IAM_SSO_CACHE_STORE'), // null = default cache store aplikasi
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement path menu (opsional)
    |--------------------------------------------------------------------------
    |
    | Bila true, middleware `iam.auth` otomatis abort(403)/redirect saat
    | path request tidak ada di daftar menu (path) yang boleh dilihat
    | user — pengganti langsung `AccessController::isAccessible()` +
    | tabel `tbmaster_access` di IAS lama. Matikan (default) bila kamu
    | lebih suka mengontrol akses per-route lewat middleware
    | `iam.permission:KODE:aksi`, supaya tidak dobel pengecekan.
    */
    'enforce_menu_paths' => env('IAM_SSO_ENFORCE_MENU_PATHS', false),

    /*
    |--------------------------------------------------------------------------
    | Perilaku saat akses ditolak
    |--------------------------------------------------------------------------
    */
    'access_denied' => [
        // 'iam' -> arahkan browser ke halaman IAM (pesan lebih jelas utk user).
        // 'abort' -> HTTP 403 di aplikasi ini.
        'action' => env('IAM_SSO_ACCESS_DENIED_ACTION', 'iam'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP client ke OMI-IAM
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout' => (float) env('IAM_SSO_HTTP_TIMEOUT', 10),
        'connect_timeout' => (float) env('IAM_SSO_HTTP_CONNECT_TIMEOUT', 5),
        'verify' => env('IAM_SSO_HTTP_VERIFY_SSL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verifikasi JWT (klaim identitas RS256 dari /api/v1/me)
    |--------------------------------------------------------------------------
    |
    | JWT ini terpisah dari access token OAuth: tujuannya supaya client
    | app bisa meneruskan identitas user ke servicenya sendiri (mis.
    | microservice internal) tanpa panggil ulang ke OMI-IAM. Isi salah
    | satu bila ingin memverifikasi signature-nya; kalau dikosongkan,
    | paket ini hanya men-decode klaimnya tanpa verifikasi signature.
    | OMI-IAM belum mempublikasikan endpoint JWKS untuk mengambil public
    | key ini secara otomatis — lihat README paket ini bagian
    | "JWT identitas" untuk cara mendapatkannya.
    */
    'jwt' => [
        'public_key' => env('IAM_SSO_JWT_PUBLIC_KEY'), // isi PEM langsung
        'public_key_path' => env('IAM_SSO_JWT_PUBLIC_KEY_PATH'), // atau path file .pem
        'algo' => env('IAM_SSO_JWT_ALGO', 'RS256'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client API Key (machine-to-machine, opsional)
    |--------------------------------------------------------------------------
    |
    | Hanya diisi bila aplikasi ini juga perlu memanggil endpoint
    | /api/v1/client/* (katalog menu, sync menu saat deploy, dsb) di luar
    | konteks user yang sedang login. Format "prefix.secret" dari
    | `php artisan iam:issue-client-key` di sisi OMI-IAM.
    */
    'client_api_key' => env('IAM_SSO_CLIENT_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Menu bawaan aplikasi ini (opsional)
    |--------------------------------------------------------------------------
    |
    | Bila diisi, `php artisan iam:sync-menus` akan mendorong daftar ini
    | ke OMI-IAM lewat POST /api/v1/client/menus/sync (butuh
    | client_api_key dengan scope menu.sync). Menu yang tidak disebutkan
    | tidak dihapus di sisi IAM, hanya dilaporkan sebagai "missing".
    | Format satu baris per menu, contoh:
    |
    |   ['code' => 'BO141', 'name' => 'Laporan Biaya Barang Hilang',
    |    'parent_code' => 'BACKOFFICE', 'type' => 'LINK',
    |    'path' => '/bo/laporan/biaya-hilang', 'actions' => ['view','export']],
    */
    'menus' => [],
];
