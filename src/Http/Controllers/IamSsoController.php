<?php

namespace Sd1\IamSsoClient\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sd1\IamSsoClient\Exceptions\IamApiException;
use Sd1\IamSsoClient\Exceptions\IamAuthenticationException;
use Sd1\IamSsoClient\IamManager;

class IamSsoController extends Controller
{
    /** @var IamManager */
    protected $iam;

    public function __construct(IamManager $iam)
    {
        $this->iam = $iam;
    }

    /**
     * GET /{prefix}/redirect — mulai alur SSO: arahkan browser ke
     * halaman login OMI-IAM. Ini yang dipanggil middleware `iam.auth`
     * saat user belum login, dan bisa juga dipasang langsung sebagai
     * pengganti rute /login lama.
     */
    public function redirect(Request $request)
    {
        if ($this->iam->check()) {
            return redirect()->to($request->query('intended') ?: config('iam-sso.routes.home_route', '/'));
        }

        return redirect()->away(
            $this->iam->redirectUrl($request->query('intended'))
        );
    }

    /**
     * GET /{prefix}/callback — OMI-IAM mengembalikan browser ke sini
     * setelah login berhasil, membawa "code" & "state".
     */
    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return $this->failed($request, (string) ($request->query('error_description') ?: $request->query('error')));
        }

        try {
            $this->iam->handleCallback($request->query('code'), $request->query('state'));
        } catch (IamAuthenticationException $e) {
            return $this->failed($request, $e->getMessage());
        } catch (IamApiException $e) {
            return $this->failed($request, $e->getMessage());
        }

        $destination = $this->iam->pullIntendedUrl() ?: config('iam-sso.routes.home_route', '/');

        return redirect()->to($destination);
    }

    protected function failed(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => $message], 401);
        }

        // Sengaja TIDAK redirect ke home_route: kalau home_route dilindungi
        // middleware iam.auth, session yang gagal login (lihat pembersihan
        // di IamManager::handleCallback()) akan dianggap "belum login" oleh
        // EnsureIamAuthenticated dan diarahkan balik ke authorize -> callback
        // gagal lagi -> redirect lagi -> infinite redirect loop. Tampilkan
        // halaman gagal login yang berdiri sendiri (route tanpa iam.auth).
        //
        // Catatan: link "Login Ulang" mengarah ke route iam.redirect secara
        // eksplisit (bukan bergantung pada home_route yang mungkin tidak
        // dilindungi iam.auth). Tapi kalau browser masih punya sesi SSO aktif
        // di OMI-IAM, klik ini TIDAK akan menampilkan form login lagi — IAM
        // akan auto-approve karena user sudah authenticated di sana, lalu
        // callback akan gagal lagi dengan alasan yang sama selama akses user
        // belum di-grant di sisi IAM. Untuk memaksa form login IAM muncul
        // lagi, sesi SSO di IAM sendiri perlu di-logout terlebih dahulu.
        return response()->view('iam-sso::failed', [
            'message' => $message,
            'loginUrl' => route(config('iam-sso.routes.login_route', 'iam.redirect')),
            'homeUrl' => url(config('iam-sso.routes.home_route', '/')),
        ], 401);
    }

    /**
     * GET|POST /{prefix}/logout — bersihkan sesi lokal, lalu (secara
     * default) arahkan browser ke /logout milik OMI-IAM supaya sesi SSO
     * ikut berakhir untuk aplikasi lain juga. Lihat iam-sso.logout di config.
     */
    public function logout(Request $request)
    {
        $returnUrl = config('iam-sso.routes.home_route', '/');
        $ssoUrl = $this->iam->ssoLogoutUrl(url($returnUrl));

        $this->iam->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($ssoUrl) {
            return redirect()->away($ssoUrl);
        }

        return redirect()->to($returnUrl);
    }
}