<?php

namespace Sd1\IamSsoClient;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Sd1\IamSsoClient\Auth\IamGuard;
use Sd1\IamSsoClient\Client\IamApiClient;
use Sd1\IamSsoClient\Console\SyncMenusCommand;
use Sd1\IamSsoClient\Http\Middleware\EnsureIamAuthenticated;
use Sd1\IamSsoClient\Http\Middleware\EnsureIamPermission;
use Sd1\IamSsoClient\Support\IamSession;

class IamSsoServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/iam-sso.php', 'iam-sso');

        $this->app->singleton(IamApiClient::class, function (Application $app) {
            return new IamApiClient($app['config']->get('iam-sso'));
        });

        $this->app->singleton(IamSession::class, function (Application $app) {
            return new IamSession(
                $app['session.store'],
                $app['config']->get('iam-sso.session_prefix', '_iam_sso')
            );
        });

        $this->app->singleton(IamManager::class, function (Application $app) {
            return new IamManager(
                $app->make(IamApiClient::class),
                $app->make(IamSession::class),
                $app['config']->get('iam-sso')
            );
        });

        // Alias singkat, supaya bisa app(IamManager::class) atau app('iam').
        $this->app->alias(IamManager::class, 'iam');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/iam-sso.php' => config_path('iam-sso.php'),
        ], 'iam-sso-config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'iam-sso');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/iam-sso'),
        ], 'iam-sso-views');

        $router = $this->app['router'];
        $router->aliasMiddleware('iam.auth', EnsureIamAuthenticated::class);
        $router->aliasMiddleware('iam.permission', EnsureIamPermission::class);

        Auth::extend('iam', function (Application $app) {
            return new IamGuard($app->make(IamManager::class));
        });

        if ($this->app['config']->get('iam-sso.routes.enabled', true)) {
            $this->app['router']
                ->prefix($this->app['config']->get('iam-sso.routes.prefix', 'iam'))
                ->middleware($this->app['config']->get('iam-sso.routes.middleware', ['web']))
                ->group(__DIR__ . '/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncMenusCommand::class,
            ]);
        }
    }
}