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
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Contracts\AuthUserExtrasContributor;
use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;
use Splicewire\Beam\Accounts\Entitlements\EntitlementComposer;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Fortify\ResetUserPassword;
use Splicewire\Beam\Accounts\Http\Controllers\LoginAsController;
use Splicewire\Beam\Accounts\Http\Controllers\ShareLinkController;
use Splicewire\Beam\Accounts\Http\Middleware\SetCurrentTeamPermissions;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Sharing\ShareLinkScopes;
use Splicewire\Beam\Accounts\Support\Demo;
use Splicewire\Beam\Accounts\Support\NullAccountShellProvider;
use Splicewire\Beam\Accounts\Support\NullAuthUserExtras;
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

        // The share-link scope-handler registry (tracer 06) — a singleton so a host registers
        // its handlers (in boot) on the same instance the /s/{token} resolver reads.
        $this->app->singleton(ShareLinkScopes::class);

        $this->app->singleton(TeamProvisioner::class);

        // The declarative entitlement-bundle layer (Frame OS ticket 09): the BundleRegistry reads the
        // host-declared `name => keys` bundles from config; the EntitlementComposer folds a bundle
        // baseline with per-principal grants/denies (`plan-baseline ∪ grants − denies`). Both are pure
        // — no plan model — so a host's bound EntitlementResolver composes them over its own plan/grant
        // discovery. Singletons so the resolved config is read once per request.
        $this->app->singleton(BundleRegistry::class, fn () => new BundleRegistry);
        $this->app->singleton(EntitlementComposer::class);

        // The account-shell provider seam (ticket 08): the package projects the SHAPE
        // (AccountShellData); the host binds a provider that fills it from its own product data.
        // Default to the null provider so consuming the shell prop is safe OOTB — an unbound host
        // gets `null` and the shell degrades gracefully rather than erroring. Bound with `bind`
        // (not `singleton`) so a host override takes precedence with the same lazy semantics.
        $this->app->bind(AccountShellProvider::class, NullAccountShellProvider::class);

        // The auth-projection extension seam (HTTP-06 / extension-seam asset 07). The base
        // AuthUserData carries only the identity core; a host adds fields via two idioms — the
        // config-swappable SHAPE class (`beam.accounts.data.auth_user`, defaulted below) and this
        // bound VALUE contributor. Default to the Null contributor so a standalone beam-accounts site
        // projects a coherent identity core with the host fields simply ABSENT (not empty). Bound with
        // `bind` so a host override wins with the same lazy semantics.
        $this->app->bind(AuthUserExtrasContributor::class, NullAuthUserExtras::class);

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
        $this->bootShareLinks();
    }

    /**
     * The reusable link-only front door (tracer 06): a PUBLIC `GET /s/{token}` that resolves a
     * ShareLink through the host-registered {@see ShareLinkScopes}. Gated by
     * `beam.accounts.share_links.enabled` — the ShareLink primitive stays callable from PHP
     * either way; this only mounts the guest route.
     */
    protected function bootShareLinks(): void
    {
        if (! config('beam.accounts.share_links.enabled', true)) {
            return;
        }

        Route::middleware('web')->group(function () {
            Route::get('/s/{token}', [ShareLinkController::class, 'resolve'])->name('beam.share-link.resolve');
        });
    }

    protected function bootConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/beam/accounts.php' => $this->app->configPath('beam/accounts.php'),
            ], 'beam-accounts-config');
        }
    }

    /**
     * Home the account engine's own migrations across TWO independently-gated estates —
     * separated (recohere RCH-12) so a host can take the auth schema without the
     * teams schema, or vice versa:
     *
     *  - AUTH (cluster C2) — the app's real auth schema, homed here to align with the
     *    auth CODE that already lives in this package (users, permission tables, PAT
     *    provenance/archived alters, google_id, passkeys; and the tenant estate: tenant
     *    permission tables, userables, per-tenant users, role doctrine, guest_tokens,
     *    sign_offs, system_account rename). Gated by `register_auth_migrations`. The
     *    platform app (Sanctum, composing over its own `tenant_users`) turns the
     *    teams estate OFF but keeps this ON — this IS its auth schema.
     *  - TEAMS — the engine's teams/memberships/invitations/access-grants/share-links
     *    tables under `migrations/teams`. Gated by `register_migrations` (unchanged
     *    semantics). A host composing the team PRIMITIVE over its own tables turns this
     *    off so the engine tables are never created.
     *
     * Each estate registers both a CENTRAL dir (auto-discovered by `migrate` via
     * {@see loadMigrationsFrom()}) and, where present, a `tenant/` subdir pushed onto
     * Stancl's `config('tenancy.migration_parameters.--path')` array (tenancy has no
     * auto-discovery for tenant migrations; the `tenants:migrate` command reads that
     * array at runtime, and boot runs well before it). Mirrors the same idiom in
     * splicewire/tower's TowerServiceProvider::bootMigrations() (no code dependency —
     * beam is DOWN from tower).
     */
    protected function bootMigrations(): void
    {
        // AUTH estate (cluster C2) — the host's real auth schema.
        if (config('beam.accounts.register_auth_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
            $this->pushTenantMigrationPath(__DIR__.'/../database/migrations/tenant');
        }

        // TEAMS estate — the engine's teams/memberships/invitations tables.
        if (config('beam.accounts.register_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations/teams');
        }
    }

    /**
     * Push a package tenant-migration dir onto Stancl's runtime `--path` array,
     * install-location-agnostic and idempotent.
     */
    protected function pushTenantMigrationPath(string $dir): void
    {
        $tenantPath = realpath($dir) ?: $dir;

        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($tenantPath, $paths, true)) {
            config()->push('tenancy.migration_parameters.--path', $tenantPath);
        }
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
     * the controller requires a signed link (the `splicewire:beam:accounts:login-as` command mints one), so
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
     * available to PHP callers; only the host-facing `splicewire:beam:accounts:mint-key` command is
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
