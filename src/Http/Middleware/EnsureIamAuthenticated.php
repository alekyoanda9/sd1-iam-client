<?php

namespace Sd1\IamSsoClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sd1\IamSsoClient\IamManager;

/**
 * Pengganti CheckLogin milik IAS.
 *
 * Perbedaan penting dari versi lama: dulu tiap request memanggil DB +
 * API IAS untuk cek/perpanjang token (lihat blok if(!$token...) di
 * CheckLogin lama). Di sini token & menu HANYA diperiksa ulang ke
 * OMI-IAM saat benar-benar kedaluwarsa / cache-nya sudah melewati TTL
 * (iam-sso.access_cache.ttl) — lihat IamManager::ensureFresh().
 *
 * Pasang di route yang butuh login:
 *   Route::middleware('iam.auth')->group(function () { ... });
 *
 * Opsional, pengganti pengecekan `AccessController::isAccessible()`
 * berbasis tabel tbmaster_access di IAS lama: set
 * iam-sso.enforce_menu_paths = true di config untuk otomatis abort(403)
 * bila path request tidak ada di daftar menu yang boleh dilihat user.
 * Kalau enforcement akses sudah/akan ditangani per-route lewat
 * `iam.permission:KODE:aksi`, biarkan false (default) supaya tidak dobel.
 */
class EnsureIamAuthenticated
{
    /** @var IamManager */
    protected $iam;

    public function __construct(IamManager $iam)
    {
        $this->iam = $iam;
    }

    public function handle(Request $request, Closure $next)
    {
        if (! $this->iam->check()) {
            return $this->unauthenticated($request);
        }

        if (! $this->iam->ensureFresh()) {
            return $this->unauthenticated($request);
        }

        if (config('iam-sso.enforce_menu_paths', false) && ! $this->iam->isPathAllowed($request->getPathInfo())) {
            return $this->accessDenied($request);
        }

        return $next($request);
    }

    protected function unauthenticated(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => 'Sesi login tidak valid atau sudah berakhir.'], 401);
        }

        $loginRoute = config('iam-sso.routes.login_route', 'iam.redirect');

        return redirect()->to(route($loginRoute, ['intended' => $request->fullUrl()]));
    }

    protected function accessDenied(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => 'Anda tidak memiliki akses ke halaman ini.'], 403);
        }

        if (config('iam-sso.access_denied.action', 'iam') === 'abort') {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return redirect(config('iam-sso.base_url') . '/dashboard')
            ->with('status', 'Anda tidak memiliki akses ke halaman tersebut.');
    }
}
