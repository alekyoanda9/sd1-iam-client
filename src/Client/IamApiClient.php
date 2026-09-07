<?php

namespace Sd1\IamSsoClient\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Sd1\IamSsoClient\Exceptions\IamApiException;

/**
 * Satu-satunya titik yang bicara langsung HTTP ke OMI-IAM.
 *
 * Sengaja tidak memakai facade Http (Illuminate\Support\Facades\Http,
 * baru ada sejak Laravel 7) supaya paket ini tetap jalan di aplikasi
 * Laravel 5.8 (IAS) maupun yang lebih baru dengan kode yang sama persis.
 * Guzzle sendiri sudah menjadi dependency wajib laravel/framework di
 * semua versi tersebut.
 */
class IamApiClient
{
    /** @var Client */
    protected $http;

    /** @var array */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->http = new Client([
            'base_uri' => $config['base_url'] . '/',
            'timeout' => $config['http']['timeout'] ?? 10,
            'connect_timeout' => $config['http']['connect_timeout'] ?? 5,
            'verify' => $config['http']['verify'] ?? true,
            'http_errors' => false,
        ]);
    }

    // ------------------------------------------------------------------
    // OAuth2
    // ------------------------------------------------------------------

    /** URL halaman authorize IAM untuk redirect browser (Authorization Code). */
    public function authorizeUrl(string $redirectUri, string $state, string $scope = ''): string
    {
        $query = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
        ];

        return $this->config['base_url'] . '/oauth/authorize?' . http_build_query($query);
    }

    /** Tukar authorization code dengan access + refresh token. */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        return $this->postToken([
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
    }

    /** Login langsung dengan username/password (grant "password"). */
    public function loginWithPassword(string $username, string $password, string $scope = ''): array
    {
        return $this->postToken([
            'grant_type' => 'password',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'username' => $username,
            'password' => $password,
            'scope' => $scope !== '' ? $scope : ($this->config['scope'] ?? ''),
        ]);
    }

    /** Perpanjang access token yang sudah kedaluwarsa/hampir kedaluwarsa. */
    public function refreshToken(string $refreshToken): array
    {
        return $this->postToken([
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
        ]);
    }

    protected function postToken(array $form): array
    {
        try {
            $response = $this->http->post('oauth/token', ['form_params' => $form]);
        } catch (RequestException $e) {
            throw IamApiException::connectionFailed($e);
        }

        $data = $this->decode($response);

        if ($response->getStatusCode() >= 400) {
            $message = $data['message'] ?? ($data['error_description'] ?? 'Gagal melakukan autentikasi ke OMI-IAM.');
            throw new IamApiException($message, $response->getStatusCode(), $data);
        }

        // Passport mengembalikan token_type/expires_in/access_token/refresh_token
        // secara flat (bukan dibungkus amplop {status,message,data} BaseController,
        // karena /oauth/token bukan endpoint aplikasi kita, melainkan bawaan Passport).
        return $data;
    }

    // ------------------------------------------------------------------
    // API v1 — konteks user (Bearer access token)
    // ------------------------------------------------------------------

    /** Profil + role + menu + permission dalam satu panggilan. */
    public function me(string $accessToken): array
    {
        return $this->getAuthorized('api/v1/me', $accessToken);
    }

    /**
     * Menu + permission. Kirim $version hasil panggilan sebelumnya supaya
     * IAM bisa membalas ringan ({"changed": false, ...}) bila tidak berubah.
     */
    public function menus(string $accessToken, ?string $version = null): array
    {
        $query = $version ? ['version' => $version] : [];

        return $this->getAuthorized('api/v1/access/menus', $accessToken, $query);
    }

    /** Cadangan saat permission belum ada di cache lokal. */
    public function checkPermission(string $accessToken, string $permission): array
    {
        return $this->postAuthorized('api/v1/access/check', $accessToken, ['permission' => $permission]);
    }

    protected function getAuthorized(string $path, string $accessToken, array $query = []): array
    {
        try {
            $response = $this->http->get($path, [
                'headers' => $this->authHeaders($accessToken),
                'query' => $query,
            ]);
        } catch (RequestException $e) {
            throw IamApiException::connectionFailed($e);
        }

        return $this->unwrap($response);
    }

    protected function postAuthorized(string $path, string $accessToken, array $json = []): array
    {
        try {
            $response = $this->http->post($path, [
                'headers' => $this->authHeaders($accessToken),
                'json' => $json,
            ]);
        } catch (RequestException $e) {
            throw IamApiException::connectionFailed($e);
        }

        return $this->unwrap($response);
    }

    protected function authHeaders(string $accessToken): array
    {
        return [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept' => 'application/json',
        ];
    }

    // ------------------------------------------------------------------
    // API v1 — machine-to-machine (Client API Key)
    // ------------------------------------------------------------------

    public function clientMenuCatalog(): array
    {
        return $this->getWithClientKey('api/v1/client/menus');
    }

    public function syncMenus(array $menus, bool $dryRun = false): array
    {
        return $this->postWithClientKey('api/v1/client/menus/sync', [
            'menus' => $menus,
            'dry_run' => $dryRun,
        ]);
    }

    public function clientUsers(): array
    {
        return $this->getWithClientKey('api/v1/client/users');
    }

    public function clientRoles(): array
    {
        return $this->getWithClientKey('api/v1/client/roles');
    }

    protected function getWithClientKey(string $path, array $query = []): array
    {
        try {
            $response = $this->http->get($path, [
                'headers' => $this->clientKeyHeaders(),
                'query' => $query,
            ]);
        } catch (RequestException $e) {
            throw IamApiException::connectionFailed($e);
        }

        return $this->unwrap($response);
    }

    protected function postWithClientKey(string $path, array $json = []): array
    {
        try {
            $response = $this->http->post($path, [
                'headers' => $this->clientKeyHeaders(),
                'json' => $json,
            ]);
        } catch (RequestException $e) {
            throw IamApiException::connectionFailed($e);
        }

        return $this->unwrap($response);
    }

    protected function clientKeyHeaders(): array
    {
        if (empty($this->config['client_api_key'])) {
            throw new IamApiException(
                'iam-sso.client_api_key belum diisi (IAM_SSO_CLIENT_API_KEY). ' .
                'Terbitkan lewat "php artisan iam:issue-client-key" di sisi OMI-IAM.',
                0
            );
        }

        return [
            'X-Client-Key' => $this->config['client_api_key'],
            'Accept' => 'application/json',
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Endpoint API v1 (bukan /oauth/*) selalu membalas dengan amplop
     * BaseController: {"status": "success"|"error", "message": "...", "data": {...}}.
     */
    protected function unwrap($response): array
    {
        $data = $this->decode($response);

        if ($response->getStatusCode() >= 400 || ($data['status'] ?? null) === 'error') {
            $message = $data['message'] ?? 'Permintaan ke OMI-IAM gagal.';
            throw new IamApiException($message, $response->getStatusCode(), $data);
        }

        return $data['data'] ?? [];
    }

    protected function decode($response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['message' => $body];
    }
}
