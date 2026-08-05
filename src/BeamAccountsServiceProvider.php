<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\Console\LoginAsCommand;
use Splicewire\Beam\Accounts\Console\MintKeyCommand;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Fortify\ResetUserPassword;
use Splicewire\Beam\Accounts\Http\Controllers\LoginAsController;
use Splicewire\Beam\Accounts\Http\Middleware\SetCurrentTeamPermissions;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Support\Demo;
use Splicewire\Beam\Accounts\Teams\TeamMembers;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;

/**
 * The account engine: Fortify/session as the default auth substrate, the self-service
 * account runtime (profile/security surface), the generic team/membership primitives on
 * the permission-cascade base leaf, and the per-host key-management module (opt-in). The
 * former splicewire/laravel-satellite-account is retired into this engine (ADR-0104);
 * concrete tenant-provisioning + demo live in the consuming host itself (splicewire-app,
 * numero, …), which layers on top of this engine — this is the engine, not the satellite.
 */
class BeamAccountsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam/accounts.php', 'beam.accounts');

        // OOTB directory-ACL grant model: permission-cascade is model-free, so supply the
        // default grant model unless the host has bound its own. Lazily consumed by the
        // cascade at grant-query time, so setting it here (before boot) is early enough.
        if (config('permission-cascade.grant_model') === null) {
            config(['permission-cascade.grant_model' => AccessGrant::class]);
        }

        $this->app->singleton(TeamProvisioner::class);

        if ($this->app->runningInConsole()) {
            $this->commands([LoginAsCommand::class]);
        }
    }

    public function boot(): void
    {
        $this->bootConfig();
        $this->bootMigrations();
        $this->bootAuthorization();
        $this->bootMiddleware();
        $this->bootRouteMacro();
        $this->bootRoutes();
        $this->bootFortify();
        $this->bootApiGuardSeam();
        $this->bootApiGuardEnforcement();
        $this->bootDemo();
        $this->bootKeys();
    }

    protected function bootConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam/accounts.php' => $this->app->configPath('beam/accounts.php'),
            ], 'beam-accounts-config');
        }
    }

    protected function bootMigrations(): void
    {
        if (! config('beam.accounts.register_migrations', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * The team-membership authorization seam. Registers the membership abilities so
     * every consumer — the engine's own {@see TeamMembers} lifecycle and any host
     * controller — authorizes through one named check instead of hand-rolling role
     * comparisons. Two graduated tiers on the one membership axis: `manageMembers`
     * (change role / remove / ownership transfer) is owner-only; `manageInvitations`
     * (send / resend / revoke) admits owners and admins. See {@see MembershipPolicy}.
     */
    protected function bootAuthorization(): void
    {
        Gate::define('manageMembers', [MembershipPolicy::class, 'manageMembers']);
        Gate::define('manageInvitations', [MembershipPolicy::class, 'manageInvitations']);

        // A share link is managed (revoked) by its minter (ADR-0009, tracer 05). Not
        // team-scoped like invitations — the check is minter-ownership, compared as strings
        // since created_by is a cross-host string key.
        Gate::define('manageShareLinks', function ($user, ShareLink $link) {
            return $link->created_by !== null
                && (string) $link->created_by === (string) $user->getAuthIdentifier();
        });
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
            $config = config('beam.accounts.routes');

            Route::prefix($config['prefix'] ?? 'settings')
                ->middleware($config['middleware'] ?? ['web', 'auth'])
                ->group(__DIR__.'/../routes/account.php');
        });
    }

    protected function bootRoutes(): void
    {
        if (config('beam.accounts.register_routes', true)) {
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
        if (! config('beam.accounts.bootstrap_fortify', true)) {
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
     * The demo verification path — a signed login-as route that lands you in the app as a
     * known subject. Registered only when demo affordances are live (non-production by
     * default — the `beam.accounts.demo.enabled` config gate). Outside local/testing
     * the controller requires a signed link (the `splicewire:beam:account-login-as` command mints one), so
     * it opens no back door in a preview deploy. An engine affordance, config-gated — a
     * satellite no longer hand-wires it.
     */
    protected function bootDemo(): void
    {
        if (! Demo::enabled()) {
            return;
        }

        Route::middleware('web')
            ->prefix(config('beam.accounts.demo.login_as_prefix', 'account/login-as'))
            ->group(function () {
                Route::get('{subject}', LoginAsController::class)->name('splicewire.account.login-as');
            });
    }

    /**
     * The per-host key-management module. beam operates separately from splicewire, so a
     * beam site manages keys only for itself — this registers no cross-host reach and no
     * central store. The reproducible primitive (Keys\DeterministicToken) is always
     * available to PHP callers; only the host-facing `splicewire:beam:accounts-mint-key` command is
     * gated, opt-in per host (default-off), mirroring the `api` seam.
     */
    protected function bootKeys(): void
    {
        if (! config('beam.accounts.keys.enabled', false)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([MintKeyCommand::class]);
        }
    }

    /**
     * Prepare the second door — a token `api` guard alongside Fortify's `web` guard —
     * but only wire it when a real consumer opts in via `api.enabled`. Default-off,
     * so the session/Inertia surface is untouched and no API is exposed.
     */
    protected function bootApiGuardSeam(): void
    {
        if (! config('beam.accounts.api.enabled', false)) {
            return;
        }

        $name = config('beam.accounts.api.guard', 'api');

        config([
            "auth.guards.{$name}" => [
                'driver' => config('beam.accounts.api.driver', 'sanctum'),
                'provider' => config('beam.accounts.api.provider')
                    ?? config('auth.guards.'.config('beam.accounts.guard', 'web').'.provider', 'users'),
            ],
        ]);
    }

    /**
     * The scoped-PAT enforcement seam (ADR-0109). When a host opts in, source the
     * permission-cascade's credential-scope from the acting API token's abilities, so
     * `effective authority = token abilities ∩ user's live permissions` is applied at
     * every policy-gated route through the cascade's one decision point. Default-off and
     * a pure no-op when off (the cascade's null resolver leaves authorization unchanged).
     *
     * This binds the *scope source* only; the cascade owns the narrowing rule and takes no
     * Sanctum dependency. Bound in boot (after the cascade's register-time default) so this
     * override wins at request-time resolution.
     */
    protected function bootApiGuardEnforcement(): void
    {
        if (! config('beam.accounts.api.scope_enforcement', false)) {
            return;
        }

        $this->app->singleton(CredentialScopeResolver::class, TokenAbilitiesScopeResolver::class);
    }
}
