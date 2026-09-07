<?php

namespace Sd1\IamSsoClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sd1\IamSsoClient\IamManager;

/**
 * Pengecekan permission per-route, dibaca dari cache lokal (lihat
 * IamManager::can() — hanya memanggil OMI-IAM sebagai cadangan bila
 * permission tidak ditemukan di cache).
 *
 * Pemakaian, kode permission mengikuti "{code_menu}:{aksi}" (lihat
 * ARCHITECTURE.md bagian Permission di OMI-IAM), contoh:
 *
 *   Route::middleware('iam.permission:BO119:export')->group(...);
 *
 * Boleh lebih dari satu kode dipisah koma, akan lolos bila SALAH SATU
 * cocok (berguna untuk route yang bisa diakses beberapa aksi berbeda):
 *
 *   Route::middleware('iam.permission:BO119:view,BO119:export')->group(...);
 */
class EnsureIamPermission
{
    /** @var IamManager */
    protected $iam;

    public function __construct(IamManager $iam)
    {
        $this->iam = $iam;
    }

    /**
     * Menerima parameter secara variadic, BUKAN satu string koma. Laravel
     * sudah memecah "iam.permission:BO119:view,BO119:export" berdasarkan
     * koma sebelum memanggil handle() — masing-masing jadi argumen
     * terpisah ($permissions[0] = "BO119:view", $permissions[1] =
     * "BO119:export"). Signature string tunggal + explode(',', ...) di
     * sini TIDAK akan pernah menerima argumen kedua dan seterusnya.
     */
    public function handle(Request $request, Closure $next, string ...$permissions)
    {
        if (! $this->iam->check()) {
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Sesi login tidak valid atau sudah berakhir.'], 401);
            }

            return redirect()->to(route(config('iam-sso.routes.login_route', 'iam.redirect'), ['intended' => $request->fullUrl()]));
        }

        $codes = array_filter(array_map('trim', $permissions));
        $allowed = false;

        foreach ($codes as $code) {
            if ($this->iam->can($code)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => 'Anda tidak memiliki izin untuk aksi ini.'], 403);
            }

            abort(403, 'Anda tidak memiliki izin untuk aksi ini.');
        }

        return $next($request);
    }
}
