<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\Console\LoginAsCommand;
use Splicewire\Beam\Accounts\Console\MintKeyCommand;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Contracts\AuthUserExtrasContributor;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Data\TeamData;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Doctor\BeamAccountsMigrationsAudit;
use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\EntitlementComposer;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Fortify\ResetUserPassword;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
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
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Seed\BeamSeedManifest;

/**
 * The account engine: Fortify/session as the default auth substrate, the self-service
 * account runtime (profile/security surface), the generic team/membership primitives on
 * the permission-cascade base leaf, and the per-host key-management module (opt-in). The
 * former splicewire/laravel-satellite-account is retired into this engine (ADR-0104);
 * concrete tenant-provisioning + demo live in the consuming host itself (splicewire-app,
 * numero, …), which layers on top of this engine — this is the engine, not the satellite.
 *
 * Migrations ship as PUBLISH-ONLY spatie/laravel-package-tools stubs (the estate-wide
 * convention) — see {@see self::configurePackage()}. `register()` here still performs the
 * package's own container bindings/config-merge (unrelated to package-tools' own config/
 * migration plumbing, which runs via `parent::register()`); the former `boot()` sequence
 * (bootConfig/bootMigrations/bootAuthorization/etc) now runs from {@see self::packageBooted()},
 * since `PackageServiceProvider::boot()` calls `configurePackage()`-driven plumbing and THEN
 * `packageBooted()` — the hook point for everything this engine used to do in its own `boot()`.
 */
class BeamAccountsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-beam-accounts')
            ->hasConfigFile(['beam/accounts'])
            // Publish-only .stub migrations (NOT ->discoversMigrations(), which loads at runtime).
            // Declared order matters: creates before their alters, parents before children (FKs),
            // package-tools timestamps each entry a second apart in listed order at publish time.
            //
            // shared/  — identical central+tenant schema (picked up on both connections by
            //            beam-tenancy's registerSharedMigrationsPath() host-side wiring).
            // (bare)   — central-only.
            // tenant/  — tenant-only.
            // teams/   — the teams/memberships/invitations/access-grants/share-links/view-requests
            //            estate; NOT squashed (data-preserving renames already ran against real
            //            deployed data — see the individual stub docblocks).
            ->hasMigrations([
                'shared/create_users_table',
                'shared/create_permission_tables',
                'create_passkeys_table',
                'add_provenance_and_archived_to_personal_access_tokens_table',
                'tenant/create_userables_table',
                'tenant/create_guest_tokens_table',
                'tenant/create_sign_offs_table',
                'tenant/rename_userish_to_system_account',
                'teams/create_teams_table',
                'teams/create_memberships_table',
                'teams/add_current_team_id_to_users_table',
                'teams/create_invitations_table',
                'teams/create_access_grants_table',
                'teams/create_visibilities_table',
                'teams/create_share_links_table',
                'teams/create_view_requests_table',
                'teams/add_lifecycle_to_invitations_table',
            ]);
    }

    /**
     * The package's own container bindings/config-defaults — everything the old plain-provider
     * `register()` did, minus the config merge (now `hasConfigFile(['beam/accounts'])` in
     * {@see self::configurePackage()}). Runs after `PackageServiceProvider::register()` has
     * configured the package and registered its config, mirroring how
     * `BeamMultiTenancyServiceProvider` structured its own conversion.
     */
    public function packageRegistered(): void
    {
        // OOTB directory-ACL grant model: permission-cascade is model-free, so supply the
        // default grant model unless the host has bound its own. Lazily consumed by the
        // cascade at grant-query time, so setting it here (before boot) is early enough.
        if (config('permission-cascade.grant_model') === null) {
            config(['permission-cascade.grant_model' => AccessGrant::class]);
        }

        // UNLIKE grant_model/entitlement_resolver above, this is NOT defaulted-on here, and a host
        // should NOT set `permission-cascade.visibility_model` in its own config either — that key
        // is a single GLOBAL toggle with no per-model override, so setting it ANYWHERE moves EVERY
        // HasVisibility model in the app onto the off-table seam at once. grant_model/
        // entitlement_resolver are pure-additive when unconfigured (nothing previously read a
        // grant/entitlement, so turning them on can't disagree with an existing value) —
        // visibility_model is a STORAGE BACKEND SWITCH for a feature multiple HasVisibility models
        // across the family already use column-based (Shelf/Silo, tower's RunnerTransform,
        // beam-threads' ConversationParticle). Confirmed the hard way in rushing/audiostud: setting
        // this key app-wide broke Shelf's uuid-keyed morph query against the new table's varchar
        // column, on top of silently orphaning its real, populated `visibility` column. A model
        // that wants the off-table seam overrides `HasVisibility::permissionCascadeVisibilityModel()`
        // on ITSELF instead (see rushing/audiostud's `Composition`/`AudioSample`/`LyricPiece`) —
        // {@see \Splicewire\Beam\Accounts\Models\Visibility} just supplies the model+migration so a
        // host doesn't have to build its own.

        // OOTB entitlement resolver: bind the DefaultEntitlementResolver (staff → the staff bundle) UNLESS
        // the host declared its own via `config('permission-cascade.entitlement_resolver')`. Setting the
        // config key (not rebinding the contract) is enough — permission-cascade's provider reads this key
        // to build its EntitlementResolver singleton, and beam's registerEntitlementAbilities() then defines
        // the `entitlement:{key}` gates. A host resolver (audiostud's) wins because it sets this key first.
        // This is what turns a fresh host's operator/OS gates on without a laravel-beam edit.
        if (config('permission-cascade.entitlement_resolver') === null) {
            config(['permission-cascade.entitlement_resolver' => DefaultEntitlementResolver::class]);
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

    /**
     * `PackageServiceProvider::boot()` runs the package-tools plumbing (config publish/merge,
     * migrations publish) THEN calls this hook — so everything the engine's own former `boot()`
     * did (auth/middleware/routes/Fortify/etc) now runs from here, mirroring exactly how
     * `BeamMultiTenancyServiceProvider::packageBooted()` was structured post-conversion.
     * `bootConfig()`/`bootMigrations()` are gone — package-tools' `hasConfigFile()`/
     * `hasMigrations()` (declared in {@see self::configurePackage()}) now own that plumbing.
     */
    public function packageBooted(): void
    {
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
        $this->bootFrameResources();
        $this->bootSeed();

        // Self-register into beam-core's install manifest (order 5: users/permission_tables are
        // foundational — publish early, ahead of the default-order-100 packages that FK into them)
        // so `splicewire:beam:install` publishes this package's shared/central/tenant/teams
        // migrations with the rest of the stack. Recohere gap: this package predates the manifest
        // and was never wired in — no host has ever gotten a real users/permission_tables/teams
        // estate from a plain install command run.
        if ($this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-accounts',
                publishTags: ['beam-accounts-config', 'beam-accounts-migrations'],
                migrates: true,
                order: 5,
            );
        }

        // beam-accounts is itself an "operator" of the estate-wide publish-only stub migrations
        // convention — self-registers the doctor/operator check on ITS OWN migrations, same as
        // every other beam-* package registers it on theirs.
        if ($this->app->bound(BeamDoctorManifest::class)) {
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-accounts',
                BeamAccountsMigrationsAudit::class,
            );
        }
    }

    /**
     * Register the {@see DemoTeamSeeder} into beam's package-registered seed manifest (splicewire:beam:seed)
     * so a host's `DatabaseSeeder` no longer hand-calls it by class — it just runs `splicewire:beam:seed`
     * and every beam-* package's seeder fires, each config-gated.
     *
     * The gate is the config key `beam.accounts.demo.seed_users`. It defaults to `env('ACCOUNT_SEED_DEMO_USERS')`
     * (null), and here — mirroring {@see Demo::enabled()} — a null resolves to on-everywhere-but-production, so
     * a production `beam:seed` never fabricates demo subjects while dev/preview seed them by default. Explicit
     * config wins; the resolved boolean is written back onto the same key so the manifest's raw `config($gate)`
     * check reads the effective value.
     *
     * Inert (silently skipped) unless beam-core's {@see BeamSeedManifest} is present — a beam-accounts host
     * composed without the seed command pays nothing.
     */
    protected function bootSeed(): void
    {
        if (! class_exists(BeamSeedManifest::class)) {
            return;
        }

        // Resolve the null → non-production fallback ONCE (config($gate) can't run the environment logic),
        // and write it back so the manifest gate reads a concrete boolean.
        $flag = config('beam.accounts.demo.seed_users');
        $enabled = $flag !== null ? (bool) $flag : ! $this->app->environment('production');
        config(['beam.accounts.demo.seed_users' => $enabled]);

        $this->app->make(BeamSeedManifest::class)->register(
            package: 'splicewire/laravel-beam-accounts',
            seederClass: DemoTeamSeeder::class,
            order: 10,
            configGate: 'beam.accounts.demo.seed_users',
        );
    }

    /**
     * The account + team-admin FRAME RESOURCES (Frame OS ticket 20) — the OOTB list/detail surfaces a
     * host gets by installing beam-accounts: Tokens (list + revoke), Invitations (list + create + revoke),
     * Members (list-only). Two are attribute-declared `#[ParticleResource]` DTOs; Members is SOURCE-backed
     * (model-less pivot), registered imperatively as a raw ResourceDefinition (the model-required attribute
     * can't express it). One `register()`/`registerDefinition()` call per resource is enough for BOTH the
     * REST transport and Frame's manifest — beam's merged {@see ParticleResourceRegistry} serves both off
     * the one stored declaration (the retired `AdminResourceRegistry` used to need each resource registered
     * TWICE, once per registry; that split is gone).
     *
     * Gated by `beam.accounts.frame_resources.enabled` (default true) AND inert unless beam's particle
     * registry is present — a beam-less host silently gets nothing. A host that curates its own resource
     * roster (e.g. splicewire-app, which lists tower's tenant-scoped variants in config/frame.php) turns this
     * off and re-consumes the package DTOs directly.
     */
    protected function bootFrameResources(): void
    {
        if (! config('beam.accounts.frame_resources.enabled', true)) {
            return;
        }

        // Inert unless beam's particle registry is present (a beam-less host gets nothing).
        if (
            ! class_exists(ParticleResourceRegistry::class)
            || ! class_exists(AttributedParticleDiscovery::class)
        ) {
            return;
        }

        $attributeResources = [
            TokenData::class,
            InvitationData::class,
            TeamData::class,
        ];

        // Registered via afterResolving so it lands regardless of the beam↔beam-accounts boot order.
        $this->app->afterResolving(
            ParticleResourceRegistry::class,
            function (ParticleResourceRegistry $registry) use ($attributeResources): void {
                foreach ($attributeResources as $dataClass) {
                    $registry->register(
                        AttributedParticleDiscovery::resourceFromAttribute($dataClass)
                    );
                }

                // Members — the source-backed (model-less) list, imperatively (the model-required
                // attribute can't express it), mirroring tower's TowerFrameResourceProvider.
                $registry->registerDefinition(new ResourceDefinition(
                    key: 'members',
                    sourceKind: 'service',
                    model: null,
                    source: MembershipSource::class,
                    data: MembershipData::class,
                    creatable: false,
                    query: null,
                    editData: null,
                    policy: null,
                    form: 'bare',
                    nav: new NavMetadata(
                        label: 'Members',
                        group: 'Settings',
                        icon: 'users',
                        section: null,
                        navOrder: null,
                        routeName: null,
                    ),
                    layout: null,
                    deletable: false,
                    editable: false,
                ));
            }
        );
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
