<?php

namespace Sd1\IamSsoClient\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Membungkus seluruh baca/tulis session milik paket ini di satu tempat,
 * dengan prefix supaya tidak bentrok dengan session key aplikasi.
 *
 * Menyimpan tiga kelompok data:
 *  - token  : access_token, refresh_token, expires_at (epoch), jwt
 *  - profile: hasil /api/v1/me (nama, branch_code, role, dst — tanpa menu/permission)
 *  - access : menus (tree), permissions (flat), version, resolved_at (epoch)
 */
class IamSession
{
    /** @var Session */
    protected $session;

    /** @var string */
    protected $prefix;

    public function __construct(Session $session, string $prefix = '_iam_sso')
    {
        $this->session = $session;
        $this->prefix = $prefix;
    }

    protected function key(string $name): string
    {
        return $this->prefix . '.' . $name;
    }

    // ------------------------------------------------------------------
    // Token
    // ------------------------------------------------------------------

    public function putToken(array $tokenResponse): void
    {
        $expiresIn = (int) ($tokenResponse['expires_in'] ?? 3600);

        $this->session->put($this->key('access_token'), $tokenResponse['access_token'] ?? null);
        $this->session->put($this->key('refresh_token'), $tokenResponse['refresh_token'] ?? null);
        // Kurangi 30 detik sebagai buffer supaya kita refresh sebelum benar-benar expired.
        $this->session->put($this->key('token_expires_at'), time() + max(0, $expiresIn - 30));
    }

    public function accessToken(): ?string
    {
        return $this->session->get($this->key('access_token'));
    }

    public function refreshToken(): ?string
    {
        return $this->session->get($this->key('refresh_token'));
    }

    public function tokenExpired(): bool
    {
        $expiresAt = $this->session->get($this->key('token_expires_at'));

        return $expiresAt === null || time() >= $expiresAt;
    }

    public function hasToken(): bool
    {
        return $this->accessToken() !== null;
    }

    // ------------------------------------------------------------------
    // Profile (dari /api/v1/me, tanpa menus/permissions)
    // ------------------------------------------------------------------

    public function putProfile(array $profile): void
    {
        unset($profile['menus'], $profile['permissions']);
        $this->session->put($this->key('profile'), $profile);
    }

    public function profile(): ?array
    {
        return $this->session->get($this->key('profile'));
    }

    public function jwt(): ?string
    {
        $profile = $this->profile();

        return $profile['token'] ?? null;
    }

    // ------------------------------------------------------------------
    // Access (menu tree + permissions + version), dengan TTL revalidasi
    // ------------------------------------------------------------------

    public function putAccess(array $resolved): void
    {
        $this->session->put($this->key('access'), [
            'allowed' => $resolved['allowed'] ?? true,
            'role' => $resolved['role'] ?? null,
            'menus' => $resolved['menus'] ?? [],
            'permissions' => $resolved['permissions'] ?? [],
            'version' => $resolved['version'] ?? ($resolved['access_version'] ?? null),
            'resolved_at' => time(),
        ]);
    }

    public function access(): ?array
    {
        return $this->session->get($this->key('access'));
    }

    public function accessIsStale(int $ttlSeconds): bool
    {
        $access = $this->access();

        if (! $access) {
            return true;
        }

        if ($ttlSeconds <= 0) {
            return true;
        }

        return (time() - (int) $access['resolved_at']) >= $ttlSeconds;
    }

    public function accessVersion(): ?string
    {
        $access = $this->access();

        return $access['version'] ?? null;
    }

    // ------------------------------------------------------------------
    // OAuth "state" (CSRF) sementara antar redirect
    // ------------------------------------------------------------------

    public function putState(string $state): void
    {
        $this->session->put($this->key('oauth_state'), $state);
    }

    public function pullState(): ?string
    {
        return $this->session->pull($this->key('oauth_state'));
    }

    public function putIntendedUrl(?string $url): void
    {
        if ($url) {
            $this->session->put($this->key('intended_url'), $url);
        }
    }

    public function pullIntendedUrl(): ?string
    {
        return $this->session->pull($this->key('intended_url'));
    }

    // ------------------------------------------------------------------
    // Reset penuh (logout)
    // ------------------------------------------------------------------

    public function clear(): void
    {
        foreach (['access_token', 'refresh_token', 'token_expires_at', 'profile', 'access', 'oauth_state', 'intended_url'] as $name) {
            $this->session->forget($this->key($name));
        }
    }
}
