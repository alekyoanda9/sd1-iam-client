# sd1/iam-sso-client

SSO Client SDK Laravel untuk **OMI-IAM**. Menghubungkan aplikasi Laravel apa pun
(IAS, OMIHO, CMS-ULOK, dan aplikasi baru nanti) ke OMI-IAM sebagai Identity
Provider tunggal: autentikasi (LOCAL + NIK Indomaret/ESS), menu, dan permission
tidak lagi dikelola sendiri-sendiri oleh tiap aplikasi.

Kompatibel PHP 7.1 – 8.x dan Laravel 5.8 – 12 dalam **satu package yang sama**
(lihat "Kompatibilitas" di bawah) — jadi tidak perlu versi terpisah untuk IAS
yang masih Laravel 5.8/PHP 7.1.

## Daftar isi

1. [Konsep singkat](#konsep-singkat)
2. [Kompatibilitas](#kompatibilitas)
3. [Instalasi](#instalasi)
4. [Daftarkan client app di OMI-IAM](#daftarkan-client-app-di-omi-iam)
5. [Konfigurasi](#konfigurasi)
6. [Melindungi route (middleware)](#melindungi-route)
7. [Memakai data user](#memakai-data-user)
8. [Guard Auth:: bawaan Laravel (opsional)](#guard-auth-bawaan-laravel)
9. [Menu — kompatibilitas navbar lama](#menu--kompatibilitas-navbar-lama)
10. [Permission](#permission)
11. [Logout (SSO)](#logout-sso)
12. [JWT identitas](#jwt-identitas)
13. [Password grant — batasan penting](#password-grant--batasan-penting)
14. [Machine-to-machine (Client API Key)](#machine-to-machine-client-api-key)
15. [Troubleshooting](#troubleshooting)

---

## Konsep singkat

```
Browser -> [Aplikasi client, mis. IAS] --(belum login)--> redirect ke OMI-IAM
        <- OMI-IAM menampilkan login (LOCAL atau NIK ESS), pending-activation, dst
        -> OMI-IAM redirect balik ke /iam/callback aplikasi client dengan "code"
        -> Aplikasi client menukar code -> access_token (server-to-server, /oauth/token)
        -> Aplikasi client panggil /api/v1/me sekali -> profil + menu + permission + JWT
        -> Sesi lokal aplikasi client dibuat, isinya cache dari data di atas
```

Aplikasi client **tidak perlu tabel `users` sendiri**. Identitas sepenuhnya
berasal dari OMI-IAM dan hidup di session selama sesi HTTP berjalan. Menu &
permission di-cache lokal dan direvalidasi berkala (bukan tiap request) sesuai
anjuran `API-SDK-CONTRACT.md` di project OMI-IAM.

Ini adalah paket **generik**. Tidak ada satu baris pun di dalamnya yang tahu
soal koneksi database per-cabang IAS, tabel `tbmaster_computer`, atau hal lain
yang spesifik ke satu aplikasi — itu semua tetap urusan aplikasi masing-masing,
memakai `branch_code` yang sekarang datang dari OMI-IAM sebagai sumber
kebenaran. Lihat `docs/IAS-MIGRATION-GUIDE.md` untuk contoh konkretnya di IAS.

## Kompatibilitas

| | |
|---|---|
| PHP | 7.1 – 8.x |
| Laravel | 5.8 – 12 |
| HTTP client | Guzzle langsung (bukan facade `Http`, supaya jalan juga di Laravel 5.8) |
| Auto-discovery | Ya (Laravel 5.5+) — provider & facade `Iam` terdaftar otomatis |

Package ini sengaja ditulis tanpa syntax PHP 7.4+/8.0+/8.1+ (tanpa arrow
function, constructor property promotion, enum, readonly, match, nullsafe
`?->`, dst) justru supaya SATU package yang sama bisa dipasang di IAS (Laravel
5.8 / PHP 7.1) maupun aplikasi yang lebih baru, tanpa fork terpisah.

## Instalasi

Disarankan lewat repository VCS pribadi di GitHub, sama seperti pola yang
sudah dipakai untuk `sd1/laravel-query-viewer`. Push folder `package/` ini
menjadi repo baru (mis. `sd1-iam-sso-client`), lalu di tiap aplikasi client:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/<org-atau-user>/sd1-iam-sso-client.git" }
    ]
}
```

```bash
composer require sd1/iam-sso-client:^1.0
```

Untuk aplikasi Laravel 5.8 (IAS), pastikan `composer.json` aplikasi tidak
mengunci versi PHP di bawah 7.1. Tidak ada perbedaan versi package yang perlu
dipilih — composer akan resolve constraint yang sama untuk semua aplikasi.

Publikasikan config (opsional, kalau ingin mengubah bawaan):

```bash
php artisan vendor:publish --tag=iam-sso-config
```

## Daftarkan client app di OMI-IAM

Di OMI-IAM, menu **Clients** (App Management):

1. Buat client baru, tipe `WEBSITE`.
2. Isi `simulation_url` / `production_url` — dipakai OMI-IAM untuk memvalidasi
   redirect logout (`isAllowedRedirect()`), jadi WAJIB diisi dengan benar.
3. Redirect URI OAuth: `https://<host-aplikasi>/iam/callback` (sesuaikan
   `routes.prefix` bila diganti dari default `iam`).
4. Grant type: `authorization_code` (disarankan — lihat catatan Password
   Grant di bawah bila mempertimbangkan `password`).
5. Catat `client_id` dan `client_secret` yang diterbitkan.
6. Daftarkan App Role & petakan menu lewat menu **Role Access** di OMI-IAM
   (menggantikan perbandingan `acc_level` vs level user ala IAS lama).

## Konfigurasi

`.env` aplikasi client:

```env
IAM_SSO_BASE_URL=https://iam.internal.example
IAM_SSO_CLIENT_ID=5
IAM_SSO_CLIENT_SECRET=xxxxxxxx
IAM_SSO_GRANT=authorization_code

# Opsional
IAM_SSO_ROUTES_PREFIX=iam
IAM_SSO_HOME_ROUTE=/
IAM_SSO_ACCESS_TTL=300
IAM_SSO_LOGOUT_REMOTE=true
```

Detail tiap opsi ada di komentar `config/iam-sso.php` (dipublikasikan lewat
`vendor:publish` di atas, atau baca langsung di package).

## Melindungi route

```php
Route::middleware('iam.auth')->group(function () {
    Route::get('/dashboard', ...);
});
```

`iam.auth` menggantikan `CheckLogin` versi lama:

- Redirect ke `/iam/redirect` (lalu ke halaman login OMI-IAM) kalau belum
  login, sambil menyimpan URL yang dituju supaya user kembali ke situ setelah
  login (`?intended=...`).
- Refresh access token otomatis kalau sudah kedaluwarsa (pakai refresh_token,
  transparan, tanpa mengganggu user).
- Revalidasi menu & permission ke OMI-IAM hanya saat cache lokal sudah
  melewati TTL (`iam-sso.access_cache.ttl`, default 300 detik) — **bukan tiap
  request** seperti pola lama yang query DB tiap kali cek session.
- Kalau OMI-IAM sedang tidak bisa dihubungi saat revalidasi, cache lama tetap
  dipakai (tidak langsung men-logout user) — dicoba lagi di request
  berikutnya.

Opsional: set `IAM_SSO_ENFORCE_MENU_PATHS=true` supaya middleware ini juga
otomatis abort(403) bila path request tidak ada di daftar menu yang boleh
dilihat user — pengganti langsung `AccessController::isAccessible()` +
`tbmaster_access` di IAS lama. Matikan (default) kalau kamu lebih suka
mengatur akses per-route lewat `iam.permission:KODE:aksi` (lihat
[Permission](#permission)).

## Memakai data user

Tanpa perlu guard `Auth::`, langsung lewat facade `Iam`:

```php
use Sd1\IamSsoClient\Facades\Iam;

$user = Iam::user(); // Sd1\IamSsoClient\Auth\IamUser, atau null kalau belum login

$user->name();
$user->username();
$user->branchCode();
$user->branchName();
$user->roleName();
$user->roleLevel();
$user->id();          // id ter-hash dari OMI-IAM (Hashids), bukan primary key mentah
```

## Guard Auth:: bawaan Laravel

Kalau kode aplikasi sudah banyak memakai `Auth::user()` / `auth()->id()` /
`@auth` di Blade / `$request->user()`, daftarkan guard `iam` di
`config/auth.php`:

```php
'defaults' => [
    'guard' => 'iam',
],
'guards' => [
    'iam' => ['driver' => 'iam'],
],
```

Tidak perlu entri `providers` — guard ini tidak membaca tabel user lokal,
seluruh datanya berasal dari sesi OMI-IAM (lihat `IamGuard`). Setelah ini,
`Auth::user()` mengembalikan instance `IamUser` yang sama seperti `Iam::user()`.

`Auth::attempt(['username' => .., 'password' => ..])` juga berfungsi lewat
guard ini — dipetakan ke Password Grant OMI-IAM. Baca batasannya di
[Password grant](#password-grant--batasan-penting) sebelum dipakai untuk
login user nyata.

## Menu — kompatibilitas navbar lama

OMI-IAM mengembalikan menu sebagai **pohon** (`menus[].children[]`), bukan
daftar datar seperti `session('menu')` di IAS lama. Untuk migrasi tanpa
mengubah Blade navbar sama sekali:

```php
$user->menusAsLegacyIasFlat();
// atau
Iam::menusAsLegacyIasFlat();
```

Mengembalikan array `stdClass` persis seperti baris `tbmaster_access_migrasi`
dulu: `acc_group`, `acc_subgroup1`, `acc_subgroup2`, `acc_subgroup3`,
`acc_name`, `acc_url` (plus `acc_id`, `code`, `actions` sebagai tambahan).
Cukup ganti sumber datanya di controller:

```php
// dulu:
// Session::put('menu', AccessController::getListMenu(Session::get('usid')));

// sekarang:
view()->share('menu', Iam::menusAsLegacyIasFlat());
// atau taruh di session kalau Blade-nya memang membaca dari Session::get('menu')
```

Untuk kebutuhan yang lebih modern (mis. dipakai di Inertia/React), pakai
pohon aslinya lewat `Iam::menus()`.

## Permission

Kode permission mengikuti pola OMI-IAM: `{kode_menu}:{aksi}`, contoh
`BO141:export`.

```php
Route::middleware('iam.permission:BO141:export')->post('/laporan/export', ...);

// atau manual di controller/Blade:
if (Iam::can('BO141:export')) { ... }
```

`Iam::can()` mengecek cache lokal dulu (cepat), baru memanggil OMI-IAM sebagai
cadangan kalau kode tersebut belum ada di cache (mis. baru saja diberikan
admin dan cache belum sempat revalidasi).

## Logout (SSO)

```php
// cukup arahkan ke rute bawaan package:
<a href="{{ route('iam.logout') }}">Logout</a>
```

Yang terjadi: sesi lokal dibersihkan, lalu browser diarahkan ke `/logout`
milik OMI-IAM (supaya sesi SSO ikut berakhir untuk aplikasi lain juga), yang
kemudian mengembalikan browser ke aplikasi ini. **Redirect balik ini hanya
diizinkan OMI-IAM kalau host aplikasi terdaftar** di
`oauth_client_details.simulation_url`/`production_url` (lihat langkah
pendaftaran client) atau `IAM_ALLOWED_LOGOUT_HOSTS` di `.env` OMI-IAM — kalau
tidak, user akan berhenti di halaman login OMI-IAM alih-alih kembali.

Set `IAM_SSO_LOGOUT_REMOTE=false` untuk logout lokal saja tanpa mengakhiri
sesi SSO (jarang dibutuhkan).

## JWT identitas

`/api/v1/me` menyertakan JWT RS256 terpisah dari access token OAuth — untuk
diteruskan aplikasi ke service internalnya sendiri (mis. memanggil API
sibling) tanpa perlu memanggil ulang OMI-IAM.

```php
Iam::jwt();            // string JWT mentah
Iam::jwtClaims();       // array klaim, TANPA verifikasi signature
Iam::verifiedJwtClaims(); // array klaim, DENGAN verifikasi signature RS256
```

`verifiedJwtClaims()` butuh package `firebase/php-jwt` (`composer require
firebase/php-jwt`) dan public key OMI-IAM di `IAM_SSO_JWT_PUBLIC_KEY` /
`IAM_SSO_JWT_PUBLIC_KEY_PATH`. OMI-IAM saat ini belum mempublikasikan endpoint
untuk mengambil public key ini secara otomatis — untuk sementara minta file
`storage/app/keys/public.pem` ke tim yang mengelola OMI-IAM. Ada penambahan
kecil opsional di sisi OMI-IAM (endpoint `GET /api/v1/public-key`) yang
menyelesaikan ini secara permanen — di luar SDK ini, lihat paket sumber SDK
(folder `iam-server-addon/`, terpisah dari repo package ini).

## Password grant — batasan penting

`config('iam-sso.grant')` mendukung dua nilai:

- **`authorization_code`** (default, disarankan) — lewat halaman login
  OMI-IAM. Mendukung akun LOCAL **dan** NIK ESS Indomaret, halaman
  "menunggu aktivasi", pemilihan sumber kredensial — semua otomatis, tanpa
  kode tambahan di aplikasi client.

- **`password`** — aplikasi client punya form login sendiri, mengirim
  username/password langsung ke `/oauth/token`. **Pada kondisi OMI-IAM saat
  ini, jalur ini hanya memvalidasi akun LOCAL** (dicek lewat
  `User::findForPassport()` + hash password bawaan Passport). User dengan
  kredensial NIK Indomaret (ESS) **tidak akan berhasil login** lewat jalur
  ini, karena `AuthenticationManager` (yang menangani ESS, auto-link NIK,
  provisioning) hanya terpasang di controller halaman login web OMI-IAM, dan
  Passport belum dikonfigurasi memanggilnya lewat
  `User::validateForPassportPasswordGrant()`.

  Kalau butuh form login custom yang tetap mendukung ESS, opsinya:
  1. Sambungkan `validateForPassportPasswordGrant()` di sisi OMI-IAM ke
     `AuthenticationManager` (perubahan kecil, di luar cakupan package ini
     karena itu kode server OMI-IAM, bukan SDK client), **atau**
  2. Tetap pakai `authorization_code` — bisa dibuat terasa seperti form
     sendiri dengan meng-embed halaman login OMI-IAM di iframe/tab baru, atau
     cukup menerima bahwa user diarahkan sebentar ke domain OMI-IAM.

  `Iam::loginWithPassword()` / `IamGuard::attempt()` tetap disediakan package
  ini untuk kasus yang memang hanya perlu akun LOCAL (mis. akun servis/vendor
  tanpa NIK).

## Machine-to-machine (Client API Key)

Untuk kebutuhan di luar konteks user yang login (katalog menu, sinkronisasi
menu saat deploy):

```bash
# Di sisi OMI-IAM:
php artisan iam:issue-client-key <client_id> --name="IAS Production" --scopes=menu.read --scopes=menu.sync
```

```env
IAM_SSO_CLIENT_API_KEY=iam_xxxxxxxx.yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy
```

```php
Iam::clientMenuCatalog();
Iam::syncMenus($menus, $dryRun = false);
Iam::clientUsers();
Iam::clientRoles();
```

Atau deklarasikan menu aplikasi di `config('iam-sso.menus')` dan jalankan:

```bash
php artisan iam:sync-menus --dry-run
php artisan iam:sync-menus
```

## Troubleshooting

**"State OAuth tidak valid"** saat callback — sesi browser berbeda antara
saat redirect dan saat callback (mis. load balancer tanpa sticky session,
atau cookie session diblokir). Pastikan domain aplikasi konsisten dan cookie
session tidak di-strip proxy.

**User berhasil login di OMI-IAM tapi balik ke halaman error "Gagal
mengambil data akses user"** — user belum punya `user_accesses` untuk client
app ini. Berikan akses lewat menu App Management > User Access di OMI-IAM.

**Logout tidak kembali ke aplikasi, berhenti di OMI-IAM** — host aplikasi
belum terdaftar di `simulation_url`/`production_url` client, atau
`IAM_ALLOWED_LOGOUT_HOSTS`. Lihat [Logout (SSO)](#logout-sso).

**Menu tidak muncul walau sudah diberi akses** — cache lokal belum
revalidasi (tunggu sampai `IAM_SSO_ACCESS_TTL`, atau logout/login ulang untuk
langsung memaksa refresh).
