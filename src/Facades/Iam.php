<?php

namespace Sd1\IamSsoClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool check()
 * @method static bool guest()
 * @method static \Sd1\IamSsoClient\Auth\IamUser|null user()
 * @method static string redirectUrl(?string $intendedUrl = null)
 * @method static string redirectUri()
 * @method static \Sd1\IamSsoClient\Auth\IamUser handleCallback(?string $code, ?string $state)
 * @method static string|null pullIntendedUrl()
 * @method static \Sd1\IamSsoClient\Auth\IamUser loginWithPassword(string $username, string $password)
 * @method static bool ensureFresh()
 * @method static array menus()
 * @method static array permissions()
 * @method static array menusAsLegacyIasFlat()
 * @method static bool isPathAllowed(string $path)
 * @method static bool can(string $permission)
 * @method static string|null jwt()
 * @method static array|null jwtClaims()
 * @method static array|null verifiedJwtClaims()
 * @method static string|null accessToken()
 * @method static void logout()
 * @method static string|null ssoLogoutUrl(string $fallbackReturnUrl)
 * @method static array clientMenuCatalog()
 * @method static array syncMenus(array $menus, bool $dryRun = false)
 * @method static array clientUsers()
 * @method static array clientRoles()
 *
 * @see \Sd1\IamSsoClient\IamManager
 */
class Iam extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Sd1\IamSsoClient\IamManager::class;
    }
}
