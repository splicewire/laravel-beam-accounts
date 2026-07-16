<?php

namespace Schemastud\Beam\Accounts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Schemastud\Beam\Accounts\Fortify\CreateNewUser;
use Schemastud\Beam\Accounts\Fortify\ResetUserPassword;
use Schemastud\Beam\Accounts\Http\Middleware\SetCurrentTeamPermissions;
use Schemastud\Beam\Accounts\Teams\TeamProvisioner;

/**
 * The account engine: Fortify/session as the default auth substrate, the self-service
 * account runtime (profile/security surface), and the generic team/membership
 * primitives on the permission-cascade base leaf. Concrete tenant-provisioning + demo
 * live in the consuming satellite (splicewire/laravel-satellite-account), which layers
 * on top of this engine — this is the engine, not the satellite.
 */
class BeamAccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/splicewire/account.php', 'splicewire.account');

        $this->app->singleton(TeamProvisioner::class);
    }

    public function boot(): void
    {
        $this->bootConfig();
        $this->bootMigrations();
        $this->bootMiddleware();
        $this->bootRouteMacro();
        $this->bootRoutes();
        $this->bootFortify();
        $this->bootApiGuardSeam();
    }

    protected function bootConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/splicewire/account.php' => $this->app->configPath('splicewire/account.php'),
            ], 'beam-accounts-config');
        }
    }

    protected function bootMigrations(): void
    {
        if (! config('splicewire.account.register_migrations', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function bootMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('splicewire.team', SetCurrentTeamPermissions::class);
    }

    /**
     * Register the settings surface under a macro so a host can mount it wherever
     * it likes; the default boot mounts it for you unless `register_routes` is off.
     */
    protected function bootRouteMacro(): void
    {
        Route::macro('splicewireAccountRoutes', function () {
            $config = config('splicewire.account.routes');

            Route::prefix($config['prefix'] ?? 'settings')
                ->middleware($config['middleware'] ?? ['web', 'auth'])
                ->group(__DIR__.'/../routes/account.php');
        });
    }

    protected function bootRoutes(): void
    {
        if (config('splicewire.account.register_routes', true)) {
            Route::splicewireAccountRoutes();
        }
    }

    /**
     * Wire Fortify as the engine's default auth substrate: the registration + password-reset
     * actions and the login/two-factor rate limiters. Session/Fortify, never Passport — an
     * overridable seam (a satellite may rebind the Fortify actions), not a per-satellite fork.
     */
    protected function bootFortify(): void
    {
        if (! config('splicewire.account.bootstrap_fortify', true)) {
            return;
        }

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower($request->input(Fortify::username()).'|'.$request->ip()));

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }

    /**
     * Prepare the second door — a token `api` guard alongside Fortify's `web` guard —
     * but only wire it when a real consumer opts in via `api.enabled`. Default-off,
     * so the session/Inertia surface is untouched and no API is exposed.
     */
    protected function bootApiGuardSeam(): void
    {
        if (! config('splicewire.account.api.enabled', false)) {
            return;
        }

        $name = config('splicewire.account.api.guard', 'api');

        config([
            "auth.guards.{$name}" => [
                'driver' => config('splicewire.account.api.driver', 'sanctum'),
                'provider' => config('splicewire.account.api.provider')
                    ?? config('auth.guards.'.config('splicewire.account.guard', 'web').'.provider', 'users'),
            ],
        ]);
    }
}
