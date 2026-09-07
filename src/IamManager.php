<?php

namespace Sd1\IamSsoClient;

use Illuminate\Support\Str;
use Sd1\IamSsoClient\Auth\IamUser;
use Sd1\IamSsoClient\Client\IamApiClient;
use Sd1\IamSsoClient\Exceptions\IamApiException;
use Sd1\IamSsoClient\Exceptions\IamAuthenticationException;
use Sd1\IamSsoClient\Menu\IasMenuAdapter;
use Sd1\IamSsoClient\Support\IamSession;
use Sd1\IamSsoClient\Support\JwtDecoder;

class IamManager
{
    /** @var IamApiClient */
    protected $client;

    /** @var IamSession */
    protected $session;

    /** @var array */
    protected $config;

    /** @var IamUser|null cache in-memory untuk request berjalan */
    protected $userCache = null;

    public function __construct(IamApiClient $client, IamSession $session, array $config)
    {
        $this->client = $client;
        $this->session = $session;
        $this->config = $config;
    }

    // ------------------------------------------------------------------
    // Status login
    // ------------------------------------------------------------------

    public function check(): bool
    {
        return $this->session->hasToken() && $this->session->profile() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?IamUser
    {
        if (! $this->check()) {
            return null;
        }

        if ($this->userCache) {
            return $this->userCache;
        }

        return $this->userCache = new IamUser(
            $this->session->profile() ?: [],
            $this->session->access() ?: []
        );
    }

    // ------------------------------------------------------------------
    // Authorization Code — SSO redirect (jalur yang disarankan)
    // ------------------------------------------------------------------

    /** Bangun URL /oauth/authorize IAM dan simpan "state" + intended URL. */
    public function redirectUrl(?string $intendedUrl = null): string
    {
        $state = Str::random(40);
        $this->session->putState($state);
        $this->session->putIntendedUrl($intendedUrl);

        return $this->client->authorizeUrl($this->redirectUri(), $state, $this->config['scope'] ?? '');
    }

    public function redirectUri(): string
    {
        return $this->config['redirect_uri'] ?: route('iam.callback');
    }

    /**
     * Tangani callback dari OMI-IAM: validasi state, tukar code, ambil
     * profil+menu+permission. Melempar IamAuthenticationException bila
     * state tidak cocok, atau IamApiException bila OMI-IAM menolak.
     */
    public function handleCallback(?string $code, ?string $state): IamUser
    {
        $expected = $this->session->pullState();

        if (! $code) {
            throw new IamAuthenticationException('OMI-IAM tidak mengirim authorization code.');
        }

        if (! $state || ! $expected || ! hash_equals($expected, $state)) {
            throw new IamAuthenticationException(
                'State OAuth tidak valid. Ini bisa terjadi karena sesi kedaluwarsa atau ' .
                'percobaan CSRF — silakan login ulang.'
            );
        }

        $token = $this->client->exchangeCode($code, $this->redirectUri());
        $this->session->putToken($token);

        return $this->refreshFromMe();
    }

    /** Ambil kembali URL yang dituju user sebelum diarahkan ke login. */
    public function pullIntendedUrl(): ?string
    {
        return $this->session->pullIntendedUrl();
    }

    // ------------------------------------------------------------------
    // Password grant — opsional, lihat catatan di config/iam-sso.php
    // ------------------------------------------------------------------

    public function loginWithPassword(string $username, string $password): IamUser
    {
        $token = $this->client->loginWithPassword($username, $password, $this->config['scope'] ?? '');
        $this->session->putToken($token);

        return $this->refreshFromMe();
    }

    protected function refreshFromMe(): IamUser
    {
        $me = $this->client->me($this->session->accessToken());

        $this->session->putProfile($me);
        $this->session->putAccess([
            'allowed' => true,
            'role' => [
                'id' => null,
                'external_id' => $me['role_id'] ?? null,
                'name' => $me['role_name'] ?? null,
                'level' => $me['role_level'] ?? null,
            ],
            'menus' => $me['menus'] ?? [],
            'permissions' => $me['permissions'] ?? [],
            'version' => $me['access_version'] ?? null,
        ]);

        $this->userCache = null;

        return $this->user();
    }

    // ------------------------------------------------------------------
    // Menjaga sesi tetap segar — dipanggil oleh EnsureIamAuthenticated
    // ------------------------------------------------------------------

    /**
     * Refresh access token bila kedaluwarsa, dan revalidasi menu/permission
     * bila cache lokal sudah melewati TTL. Aman dipanggil di tiap request;
     * biaya panggilan API hanya terjadi saat memang perlu.
     *
     * @return bool false berarti sesi tidak bisa dipertahankan (harus login ulang)
     */
    public function ensureFresh(): bool
    {
        if (! $this->check()) {
            return false;
        }

        if ($this->session->tokenExpired() && ! $this->refreshAccessToken()) {
            return false;
        }

        $ttl = (int) ($this->config['access_cache']['ttl'] ?? 300);

        if ($this->session->accessIsStale($ttl)) {
            return $this->revalidateAccess();
        }

        return true;
    }

    protected function refreshAccessToken(): bool
    {
        $refreshToken = $this->session->refreshToken();

        if (! $refreshToken) {
            $this->logout();

            return false;
        }

        try {
            $token = $this->client->refreshToken($refreshToken);
            $this->session->putToken($token);

            return true;
        } catch (IamApiException $e) {
            $this->logout();

            return false;
        }
    }

    protected function revalidateAccess(): bool
    {
        try {
            $resolved = $this->client->menus($this->session->accessToken(), $this->session->accessVersion());
        } catch (IamApiException $e) {
            // OMI-IAM sedang tidak bisa dihubungi: pertahankan cache lama
            // supaya satu gangguan jaringan tidak langsung men-logout semua
            // user yang sedang aktif. Percobaan berikutnya akan mencoba lagi.
            return true;
        }

        if (($resolved['changed'] ?? true) === false) {
            $access = $this->session->access() ?: [];
            $access['resolved_at'] = time();
            $this->session->putAccess($access);

            return true;
        }

        if (($resolved['allowed'] ?? true) === false) {
            // User masih terautentikasi tapi akses ke aplikasi ini sudah
            // dicabut — bukan urusan logout, biar pemanggil (middleware)
            // yang memutuskan mau redirect ke mana.
            $this->session->putAccess($resolved);
            $this->userCache = null;

            return false;
        }

        $this->session->putAccess($resolved);
        $this->userCache = null;

        return true;
    }

    // ------------------------------------------------------------------
    // Menu & permission
    // ------------------------------------------------------------------

    public function menus(): array
    {
        $access = $this->session->access();

        return $access['menus'] ?? [];
    }

    public function permissions(): array
    {
        $access = $this->session->access();

        return $access['permissions'] ?? [];
    }

    /** Menu dalam bentuk flat ala tbmaster_access IAS lama. */
    public function menusAsLegacyIasFlat(): array
    {
        return (new IasMenuAdapter())->flatten($this->menus());
    }

    /** Path request saat ini termasuk salah satu menu yang boleh dilihat user? */
    public function isPathAllowed(string $path): bool
    {
        $allowed = (new IasMenuAdapter())->allowedPaths($this->menus());

        return in_array(rtrim($path, '/'), $allowed, true);
    }

    /**
     * Cek permission. Diutamakan dari cache lokal (cepat, tanpa panggilan
     * API); kalau tidak ketemu di cache, tanya langsung ke OMI-IAM sekali
     * sebagai cadangan (mis. permission baru saja diberikan admin dan
     * cache lokal belum sempat revalidasi).
     */
    public function can(string $permission): bool
    {
        if (in_array($permission, $this->permissions(), true)) {
            return true;
        }

        if (! $this->session->accessToken()) {
            return false;
        }

        try {
            $result = $this->client->checkPermission($this->session->accessToken(), $permission);

            return (bool) ($result['allowed'] ?? false);
        } catch (IamApiException $e) {
            return false;
        }
    }

    public function jwt(): ?string
    {
        return $this->session->jwt();
    }

    /** Klaim JWT tanpa verifikasi signature — lihat catatan di JwtDecoder. */
    public function jwtClaims(): ?array
    {
        $jwt = $this->jwt();

        return $jwt ? JwtDecoder::decode($jwt) : null;
    }

    /** Klaim JWT dengan verifikasi signature (butuh firebase/php-jwt + public key di config). */
    public function verifiedJwtClaims(): ?array
    {
        $jwt = $this->jwt();

        if (! $jwt) {
            return null;
        }

        $publicKey = $this->config['jwt']['public_key'] ?? null;
        $publicKeyPath = $this->config['jwt']['public_key_path'] ?? null;

        if (! $publicKey && $publicKeyPath && is_readable($publicKeyPath)) {
            $publicKey = file_get_contents($publicKeyPath);
        }

        if (! $publicKey) {
            throw new IamApiException(
                'iam-sso.jwt.public_key / public_key_path belum dikonfigurasi. ' .
                'Minta file public key (storage/app/keys/public.pem) ke tim yang ' .
                'mengelola OMI-IAM, atau lihat README paket ini bagian "JWT identitas".'
            );
        }

        return JwtDecoder::verify($jwt, $publicKey, $this->config['jwt']['algo'] ?? 'RS256');
    }

    public function accessToken(): ?string
    {
        return $this->session->accessToken();
    }

    // ------------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------------

    /** Bersihkan sesi lokal saja (tidak menyentuh sesi SSO di OMI-IAM). */
    public function logout(): void
    {
        $this->session->clear();
        $this->userCache = null;
    }

    /**
     * URL untuk mengakhiri sesi SSO di OMI-IAM sekaligus, supaya aplikasi
     * client app lain yang memakai SSO yang sama ikut ter-logout saat user
     * berikutnya membukanya. Null bila logout.sso_logout dimatikan di config
     * — dalam hal ini cukup panggil logout() lalu redirect lokal biasa.
     */
    public function ssoLogoutUrl(string $fallbackReturnUrl): ?string
    {
        if (! ($this->config['logout']['sso_logout'] ?? true)) {
            return null;
        }

        $back = $this->config['logout']['redirect_back_to'] ?: $fallbackReturnUrl;

        return $this->config['base_url'] . '/logout?' . http_build_query(['redirect_back' => $back]);
    }

    // ------------------------------------------------------------------
    // Machine-to-machine (Client API Key)
    // ------------------------------------------------------------------

    public function clientMenuCatalog(): array
    {
        return $this->client->clientMenuCatalog();
    }

    public function syncMenus(array $menus, bool $dryRun = false): array
    {
        return $this->client->syncMenus($menus, $dryRun);
    }

    public function clientUsers(): array
    {
        return $this->client->clientUsers();
    }

    public function clientRoles(): array
    {
        return $this->client->clientRoles();
    }
}
