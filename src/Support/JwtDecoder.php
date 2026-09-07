<?php

namespace Sd1\IamSsoClient\Support;

use Sd1\IamSsoClient\Exceptions\IamApiException;

/**
 * JWT RS256 dari /api/v1/me dimaksudkan supaya client app bisa meneruskan
 * identitas user ke servicenya sendiri tanpa memanggil ulang OMI-IAM.
 *
 * decode() TIDAK memverifikasi signature — dipakai untuk kebutuhan
 * membaca klaim saja (mis. ditampilkan di log). Untuk memverifikasi
 * signature (wajib bila JWT ini dikirim ke service LAIN yang tidak
 * mempercayai proses request sekarang begitu saja), pakai verify()
 * yang butuh package "firebase/php-jwt" dan public key OMI-IAM — lihat
 * config/iam-sso.php bagian "jwt". OMI-IAM belum mempublikasikan endpoint
 * untuk mengambil public key ini secara otomatis; minta file
 * storage/app/keys/public.pem ke tim yang mengelola OMI-IAM, atau lihat
 * addon "endpoint public key" pada paket sumber SDK ini untuk menambahkannya.
 */
class JwtDecoder
{
    /** Baca klaim tanpa verifikasi signature. */
    public static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            throw new IamApiException('Format JWT tidak valid.');
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * Verifikasi signature RS256 lalu kembalikan klaimnya. Melempar
     * IamApiException bila package firebase/php-jwt belum terpasang,
     * public key belum dikonfigurasi, atau signature/expiry tidak valid.
     */
    public static function verify(string $jwt, string $publicKeyPem, string $algo = 'RS256'): array
    {
        if (! class_exists('Firebase\\JWT\\JWT') || ! class_exists('Firebase\\JWT\\Key')) {
            throw new IamApiException(
                'Verifikasi JWT butuh package "firebase/php-jwt". Jalankan: composer require firebase/php-jwt'
            );
        }

        $jwtClass = 'Firebase\\JWT\\JWT';
        $keyClass = 'Firebase\\JWT\\Key';

        try {
            $decoded = $jwtClass::decode($jwt, new $keyClass($publicKeyPem, $algo));
        } catch (\Throwable $e) {
            throw new IamApiException('JWT tidak valid: ' . $e->getMessage(), 0, [], $e);
        }

        return json_decode(json_encode($decoded), true) ?: [];
    }

    protected static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'));
    }
}
