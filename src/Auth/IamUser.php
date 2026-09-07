<?php

namespace Sd1\IamSsoClient\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Sd1\IamSsoClient\Menu\IasMenuAdapter;

/**
 * Objek user "ringan": bukan model Eloquent, bukan hasil query database
 * lokal. Seluruh datanya berasal dari respons /api/v1/me milik OMI-IAM
 * dan hanya hidup selama sesi HTTP. Sengaja begini supaya client app
 * TIDAK perlu tabel `users` sendiri untuk memakai SSO ini.
 *
 * Implementasi Authenticatable membuat objek ini bisa dipakai lewat
 * Auth::user() / auth()->user() / @auth blade seperti user biasa,
 * lewat guard "iam" yang didaftarkan paket ini.
 */
class IamUser implements Authenticatable
{
    /** @var array */
    protected $profile;

    /** @var array */
    protected $access;

    public function __construct(array $profile, array $access = [])
    {
        $this->profile = $profile;
        $this->access = $access;
    }

    public function toArray(): array
    {
        return $this->profile;
    }

    public function __get(string $name)
    {
        return $this->profile[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->profile[$name]);
    }

    // ------------------------------------------------------------------
    // Akses cepat ke field yang paling sering dipakai
    // ------------------------------------------------------------------

    public function id()
    {
        return $this->profile['id'] ?? null;
    }

    public function name(): ?string
    {
        return $this->profile['name'] ?? null;
    }

    public function username(): ?string
    {
        return $this->profile['username'] ?? null;
    }

    public function branchCode(): ?string
    {
        return $this->profile['branch_code'] ?? null;
    }

    public function branchName(): ?string
    {
        return $this->profile['branch_name'] ?? null;
    }

    public function roleId()
    {
        return $this->profile['role_id'] ?? ($this->access['role']['external_id'] ?? null);
    }

    public function roleName(): ?string
    {
        return $this->profile['role_name'] ?? ($this->access['role']['name'] ?? null);
    }

    public function roleLevel()
    {
        return $this->profile['role_level'] ?? ($this->access['role']['level'] ?? null);
    }

    // ------------------------------------------------------------------
    // Menu & permission (disematkan saat login/refresh, lihat IamManager)
    // ------------------------------------------------------------------

    /** Pohon menu, hanya yang boleh dilihat user ini. */
    public function menus(): array
    {
        return $this->access['menus'] ?? [];
    }

    public function permissions(): array
    {
        return $this->access['permissions'] ?? [];
    }

    /**
     * Cek cepat dari cache lokal (tanpa panggilan API). Untuk kepastian
     * penuh saat permission belum tentu ada di cache, pakai Iam::can().
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /** Menu dalam bentuk flat ala `tbmaster_access` IAS lama (lihat IasMenuAdapter). */
    public function menusAsLegacyIasFlat(): array
    {
        return (new IasMenuAdapter())->flatten($this->menus());
    }

    // ------------------------------------------------------------------
    // Illuminate\Contracts\Auth\Authenticatable
    // ------------------------------------------------------------------

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id();
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        // User ini tidak pernah diautentikasi dengan password lokal di sisi
        // client app — kredensial sepenuhnya divalidasi oleh OMI-IAM.
        return null;
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
        // No-op: state "remember me" dikelola oleh sesi SSO di OMI-IAM,
        // bukan oleh cookie remember_token lokal.
    }

    public function getRememberTokenName()
    {
        return null;
    }
}
