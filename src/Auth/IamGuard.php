<?php

namespace Sd1\IamSsoClient\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Sd1\IamSsoClient\IamManager;

/**
 * Guard "iam" — supaya Auth::user(), auth()->id(), @auth/@guest di Blade,
 * dan middleware `auth:iam` bawaan Laravel bisa dipakai seperti biasa,
 * meski identitasnya sepenuhnya berasal dari OMI-IAM (bukan tabel users
 * lokal). Didaftarkan lewat Auth::extend() di IamSsoServiceProvider,
 * bukan lewat mekanisme UserProvider standar — karena tidak ada
 * penyimpanan user lokal untuk di-query ulang berdasarkan ID.
 *
 * Registrasi di config/auth.php aplikasi:
 *
 *   'guards' => [
 *       'iam' => ['driver' => 'iam'],
 *   ],
 */
class IamGuard implements StatefulGuard
{
    /** @var IamManager */
    protected $manager;

    /** @var Authenticatable|null override untuk request berjalan (lihat setUser) */
    protected $user;

    public function __construct(IamManager $manager)
    {
        $this->manager = $manager;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        return $this->manager->user();
    }

    public function id()
    {
        $user = $this->user();

        return $user ? $user->getAuthIdentifier() : null;
    }

    public function hasUser(): bool
    {
        return $this->user() !== null;
    }

    public function setUser(Authenticatable $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Dipetakan ke grant "password" OMI-IAM, supaya kode yang sudah biasa
     * memanggil Auth::attempt(['username'=>.., 'password'=>..]) tetap
     * bekerja. Terima "identifier" sebagai alias "username" karena OMI-IAM
     * menerima NIK maupun username lewat field yang sama.
     *
     * PENTING: baca catatan grant "password" di config/iam-sso.php —
     * saat ini hanya memvalidasi akun LOCAL, bukan NIK ESS.
     */
    public function attempt(array $credentials = [], $remember = false): bool
    {
        $username = $credentials['username'] ?? $credentials['identifier'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $username || ! $password) {
            return false;
        }

        try {
            $user = $this->manager->loginWithPassword($username, $password);
            $this->setUser($user);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function once(array $credentials = []): bool
    {
        return $this->attempt($credentials);
    }

    public function validate(array $credentials = []): bool
    {
        return $this->attempt($credentials);
    }

    public function login(Authenticatable $user, $remember = false): void
    {
        // Sesi (token + profil) sudah tersimpan lewat IamManager sebelum
        // guard ini dipanggil (lihat handleCallback()/loginWithPassword()).
        // Di sini kita hanya menetapkan instance user untuk request berjalan.
        $this->setUser($user);
    }

    public function loginUsingId($id, $remember = false)
    {
        // Tidak didukung: tidak ada tabel user lokal untuk di-query ulang
        // berdasarkan ID saja tanpa token OMI-IAM yang valid.
        return false;
    }

    public function onceUsingId($id)
    {
        return false;
    }

    public function viaRemember(): bool
    {
        // "Remember me" dikelola oleh sesi SSO di OMI-IAM, bukan cookie
        // remember_token lokal — lihat IamUser::getRememberToken().
        return false;
    }

    public function logout(): void
    {
        $this->manager->logout();
        $this->user = null;
    }
}
