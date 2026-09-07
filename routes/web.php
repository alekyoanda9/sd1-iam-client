<?php

use Illuminate\Support\Facades\Route;
use Sd1\IamSsoClient\Http\Controllers\IamSsoController;

/*
|--------------------------------------------------------------------------
| Rute bawaan SSO Client SDK
|--------------------------------------------------------------------------
|
| Didaftarkan otomatis oleh IamSsoServiceProvider bila
| iam-sso.routes.enabled = true (default). Prefix & middleware diatur
| lewat config/iam-sso.php ("routes.prefix", "routes.middleware").
|
| Nama rute SENGAJA tetap "iam.redirect" / "iam.callback" / "iam.logout"
| walau prefix diganti, supaya route(...) di kode aplikasi tidak perlu
| ikut berubah hanya karena prefix URL berubah.
|
*/

Route::get('/redirect', [IamSsoController::class, 'redirect'])->name('iam.redirect');
Route::get('/callback', [IamSsoController::class, 'callback'])->name('iam.callback');
Route::match(['get', 'post'], '/logout', [IamSsoController::class, 'logout'])->name('iam.logout');
