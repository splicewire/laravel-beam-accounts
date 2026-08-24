<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Console\GenerateOidcSigningKeyCommand;
use Splicewire\Beam\Accounts\Console\LoginAsCommand;
use Splicewire\Beam\Accounts\Console\MintKeyCommand;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Data\TeamData;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Doctor\BeamAccountsMigrationsAudit;
use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\EntitlementComposer;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Fortify\ResetUserPassword;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\MeController;
use Splicewire\Beam\Accounts\Http\Controllers\ShareLinkController;
use Splicewire\Beam\Accounts\Http\Middleware\SetCurrentTeamPermissions;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Accounts\Oidc\SigningKey;
use Splicewire\Beam\Accounts\Ops\LogInAsUser;
use Splicewire\Beam\Accounts\Sharing\ShareLinkScopes;
use Splicewire\Beam\Accounts\Support\NullAccountShellProvider;
use Splicewire\Beam\Accounts\Teams\TeamMembers;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
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
        // Publish-only .stub migrations (NOT ->discoversMigrations(), which loads at runtime).
        // Declared order matters: creates before their alters, parents before children (FKs),
        // package-tools timestamps each entry a second apart in listed order at publish time.
        //
        // shared/  — identical central+tenant schema (picked up on both connections by
        //            beam-tenancy's registerSharedMigrationsPath() host-side wiring).
        // (bare)   — central-only.
        // tenant/  — tenant-only.
        //
        // Two estates, each independently config-gated (restores the pre-publish-only-stub
        // semantics that `4f9ba78` silently dropped — see publish_migrations/
        // publish_auth_migrations in config/beam/accounts.php, renamed from register_* at
        // beam-docs-satellite ticket 25 and read through {@see self::publishesEstate()}):
        //
        // AUTH estate — users/permission_tables/passkeys/PAT-provenance/the tenant identity
        // estate. On by default; a host would only turn this off if it owns its own auth schema
        // entirely (mirrors the old bootMigrations() split).
        //
        // TEAMS estate — teams/memberships/invitations/access-grants/share-links/view-requests,
        // now reclassified into shared/ (was its own host-placed `teams/` directory; see
        // shared/create_teams_table.php.stub's docblock for why). A host that runs its own
        // separate team system (splicewire-app) turns this off so these tables are never
        // published onto its disk at all.
        $authMigrations = [
            'shared/create_users_table',
            'shared/create_permission_tables',
            'create_passkeys_table',
            // The CREATE has to precede its own ALTER, and until ticket 25 NOBODY in the estate
            // owned it — Sanctum only PUBLISHES its copy, so a host that never ran
            // `vendor:publish --tag=sanctum-migrations` had no table and the ALTER below no-opped
            // through its hasTable guard, silently, forever.
            'create_personal_access_tokens_table',
            'add_provenance_and_archived_to_personal_access_tokens_table',
            'tenant/create_userables_table',
            'tenant/create_guest_tokens_table',
            'tenant/create_sign_offs_table',
            'tenant/rename_userish_to_system_account',
        ];

        $teamsMigrations = [
            'shared/create_teams_table',
            'shared/create_memberships_table',
            'shared/add_current_team_id_to_users_table',
            'shared/create_invitations_table',
            'shared/create_access_grants_table',
            'shared/create_share_links_table',
            'shared/create_view_requests_table',
            'shared/create_impersonation_events_table',
        ];

        $migrations = self::publishesEstate('auth_migrations') ? $authMigrations : [];
        $migrations = array_merge(
            $migrations,
            self::publishesEstate('migrations') ? $teamsMigrations : [],
        );

        $package
            ->name('laravel-beam-accounts')
            ->hasConfigFile(['beam/accounts'])
            ->hasViews('beam-accounts')
            ->hasMigrations($migrations);
    }

    /**
     * Whether one publish estate is on, honouring both the current `publish_*` key and the
     * deprecated `register_*` one it was renamed from (beam-docs-satellite ticket 25 — the old name
     * said "register" while gating PUBLISH, contradicting the docblock three lines above it).
     *
     * EITHER key turning it off turns it off, which is the only reading that is safe under
     * `mergeConfigFrom`: a host that published `config/beam/accounts.php` before the rename carries
     * `register_auth_migrations => false` and NO `publish_auth_migrations` key, so the package
     * default (true) would merge in underneath and silently re-enable a publish that host had
     * deliberately turned off. Both default true, so a host setting neither is unaffected.
     */
    private static function publishesEstate(string $estate): bool
    {
        return (bool) config("beam.accounts.publish_{$estate}", true)
            && (bool) config("beam.accounts.register_{$estate}", true);
    }

    /**
     * The package's own container bindings/config-defaults — everything the old plain-provider
     * `register()` did, minus the config merge (now `hasConfigFile(['beam/accounts'])` in
     * {@see self::configurePackage()}). Runs after `PackageServiceProvider::register()` has
     * configured the package and registered its config, mirroring how
     * `BeamTenancyServiceProvider` structured its own conversion.
     */
    public function packageRegistered(): void
    {
        // The auth principal is pinned to a LITERAL connection name; make that name resolve
        // everywhere before anything can resolve the model. {@see self::registerCentralConnectionAlias()}
        $this->registerCentralConnectionAlias();

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

        // Binding the resolver alone is NOT enough to turn the gates on: beam-core's
        // registerEntitlementAbilities() only defines a Laravel Gate ability for keys it already
        // knows about (config('beam.core.entitlements.keys')) — an UNLISTED key is simply never
        // `Gate::define()`'d, so `can:entitlement:os.operate` 403s even when
        // DefaultEntitlementResolver::entitlementsFor() genuinely returns it. Every key this
        // resolver can EVER emit is package-known (it's the exact vocabulary in
        // DefaultEntitlementResolver::entitlementsFor()), so beam-accounts pushes its own keys in
        // here — additively (array_unique) — rather than requiring every host to hand-list them.
        // Realm-parameterized keys (`ux.{realm}.author`) are derived from beam-core's RealmRegistry
        // (operator/tenant/site/user by default, plus any host `#[Realm]` preset), so a host that
        // registers a new realm gets its gate for free too.
        $this->registerEntitlementKeys();

        // The two facade subjects. `BeamAccountsManager` is the package's host-resolution seam
        // (what `src/helpers.php` used to autoload as five namespaced functions);
        // `BeamDemoManager` is the demo-subject roster (the former `Support\Demo`). Singletons —
        // both are stateless config readers, so one instance per request is enough, and binding
        // them by class name is what lets a test `BeamAccounts::swap()` them.
        $this->app->singleton(BeamAccountsManager::class);
        $this->app->singleton(BeamDemoManager::class);

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

        // The OIDC-issuer module's two primitives, always bindable from PHP regardless of the
        // `oidc.enabled` gate (mirrors the `keys` seam's DeterministicToken posture) — only the
        // host-facing command + routes are config-gated, in bootOidc() below.
        $this->app->singleton(SigningKey::class, fn () => new SigningKey(
            path: config('beam.accounts.oidc.signing_key_path'),
        ));
        $this->app->singleton(IdentityTokenMinter::class, fn ($app) => new IdentityTokenMinter(
            key: $app->make(SigningKey::class),
            issuer: rtrim((string) config('beam.accounts.oidc.issuer'), '/'),
        ));

        if ($this->app->runningInConsole()) {
            $this->commands([LoginAsCommand::class]);
        }
    }

    /**
     * Make `central` resolve at a host that never defined it, by registering it as a copy of the
     * host's DEFAULT connection.
     *
     * {@see \Splicewire\Beam\Accounts\Models\User} pins `protected $connection = 'central'` — a
     * justified pin (`@central-floor auth`: credentials must resolve before any tenant schema is
     * selected), but a LITERAL connection name is the one expression of a pin that cannot degrade.
     * At a host with no `central` block, merely RESOLVING the model throws
     * `InvalidArgumentException: Database connection [central] not configured.` — which was 10 of the
     * estate's 12 Herd hosts and all four starters when this was measured (2026-08-22). The runbook's
     * `references/multitenancy.md` opens *"Single-tenant sites skip this entirely — their defaults are
     * central/global"*; without this alias that sentence is aspirational rather than true.
     *
     * Deliberately an ALIAS registered by the package, not a host-side duplicated connection block
     * (the standwell / splicewire-app precedent): Laravel has no connection aliasing, so every
     * hand-copied block drifts independently from the default it is supposed to mirror, and adopting
     * it means a 10-root retrofit plus a permanent scaffold obligation in four starters. Also
     * deliberately NOT a `getConnectionName()` override reading config — that converts the pin from a
     * property into a method, and `CentralPinJustificationAudit`'s `FORM_PROPERTY` stops matching it,
     * manufacturing the exact "a pin that does not look like a pin" failure the audit was built for.
     *
     * A multi-tenant broker that defines its own `central` block wins and is untouched. A
     * single-tenant host changes nothing and `central === default` silently. Runs in `register()`
     * (not boot) so the alias is in place before any provider's boot phase can resolve a model.
     *
     * Two no-op guards, both for hosts where a copy would be a lie rather than an alias: a host whose
     * `database.default` IS `central` has nothing to copy FROM (the missing block is the default block
     * — a real misconfiguration, and its own error message is more useful than ours), and a host whose
     * default connection has no config block at all is broken independently of this package.
     */
    protected function registerCentralConnectionAlias(): void
    {
        if (config('database.connections.central') !== null) {
            return;
        }

        $default = config('database.default');

        if ($default === null || $default === 'central') {
            return;
        }

        $block = config("database.connections.{$default}");

        if (! is_array($block)) {
            return;
        }

        config(['database.connections.central' => $block]);
    }

    /**
     * Push every entitlement key {@see DefaultEntitlementResolver::entitlementsFor()} can ever emit
     * into `config('beam.core.entitlements.keys')` — additively, so a host's own extra keys survive —
     * so beam-core's `registerEntitlementAbilities()` actually `Gate::define()`s them. Runs during
     * `register()` (not `boot()`/`packageBooted()`): Laravel completes every provider's register phase
     * before any provider's boot phase, so this is guaranteed to land before beam-core reads the key
     * list, regardless of provider discovery order between the two packages.
     */
    protected function registerEntitlementKeys(): void
    {
        $realms = array_keys($this->app->make(RealmRegistry::class)->all());

        $keys = [
            'ux.author',
            'os.enter',
            'os.operate',
            ...array_map(static fn (string $realm): string => "ux.{$realm}.author", $realms),
        ];

        config(['beam.core.entitlements.keys' => array_values(array_unique([
            ...(array) config('beam.core.entitlements.keys', []),
            ...$keys,
        ]))]);
    }

    /**
     * `PackageServiceProvider::boot()` runs the package-tools plumbing (config publish/merge,
     * migrations publish) THEN calls this hook — so everything the engine's own former `boot()`
     * did (auth/middleware/routes/Fortify/etc) now runs from here, mirroring exactly how
     * `BeamTenancyServiceProvider::packageBooted()` was structured post-conversion.
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
        $this->bootOidc();
        $this->bootShareLinks();
        $this->bootFrameResources();
        $this->bootMeResource();
        $this->bootSeed();
        $this->bootTeamsMigrations();
        $this->bootOperatorShell();

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

        // `beam-accounts-operator-shell` is deliberately NOT in the install manifest's auto-run
        // publishTags above — the stub is a minimal placeholder (no resource browsing, no in-place
        // editing yet), and spraying it onto every host's resources/js/pages on a plain install
        // would commit them to a page they'd immediately want to replace. Stays opaque (package-
        // rendered, no host file) for now; `vendor:publish --tag=beam-accounts-operator-shell` is
        // still there for a host that wants to eject and customize it today anyway.

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
     * (null), and here — mirroring {@see BeamDemo::enabled()} — a null resolves to on-everywhere-but-production, so
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
     * `teams/`/`tenant/` ship as publish-only stubs (spatie/laravel-package-tools `hasMigrations()`)
     * into subdirectories the stock framework migrator never recurses into — the SAME footgun
     * `shared/` has (beam-install-turnkey trap 1), just without a fix until now: beam-core's own
     * `BeamServiceProvider` registers `database/migrations/shared` for a single-tenant host, but
     * nothing registered `teams/`/`tenant/`, so a host that ran `vendor:publish` + `migrate` (or
     * even `splicewire:beam:install`, whose own verify-provisioning pass has no trap for this) got
     * "Nothing to migrate" silently — the whole accounts/teams estate never landed.
     *
     * Mirrors beam-core's `sharedMigrationsOwnedByTenancy()` guard exactly: GUARDED on the tenancy
     * provider not being present, so this never double-registers on a multi-tenant host (that
     * package owns routing `tenant/` into its per-tenant pass, and `teams/` into whichever side
     * `config/beam/accounts.php`'s multitenancy placement calls for). A single-tenant host — every
     * host today; no consumer has ever placed this estate per-tenant (see
     * `teams/create_visibilities_table`'s docblock) — runs both on its one central connection.
     * `loadMigrationsFrom` over an empty/missing directory is a harmless no-op, so this is safe
     * before the first publish too.
     */
    protected function bootTeamsMigrations(): void
    {
        if (class_exists('Splicewire\Beam\Tenancy\BeamTenancyServiceProvider')) {
            return;
        }

        $this->loadMigrationsFrom(database_path('migrations/teams'));
        $this->loadMigrationsFrom(database_path('migrations/tenant'));
    }

    /**
     * The OOTB `/operator` front-end realm — the piece "install beam, the operator realm just works"
     * was still missing (ADR-0156's `#[OperatorRealm]` preset + `DefaultEntitlementResolver`'s
     * `os.operate` entitlement already exist; nothing rendered anything at the route). A thin stats
     * roll-up landing, matching `laravel-beam-starter`'s own hand-authored `operator/dashboard.tsx` —
     * NOT the windowed `/os` desktop (retired; `@splicewire/beam-ux/shell`'s `DefaultOsDesktop` still
     * exists for a host that wants that shape, it just isn't what this route mounts).
     *
     * Two independent overrides, mirroring `bootDemo()`/`bootShareLinks()`'s idiom:
     *  - `config('beam.accounts.operator_shell.enabled', true)` — a host turns this off and defines its
     *    own `/operator` entirely.
     *  - `Route::has('operator.home')` — a host that already named its own route `operator.home` (e.g.
     *    by copying this route into its own `routes/web.php` to customize it) is never double-registered.
     *
     * The page itself (`resources/js/pages/operator/dashboard.tsx`) ships as a publish-only stub — see
     * {@see self::packageBooted()}'s `publishes()` call below — so `splicewire:beam:install` syncs the
     * real .tsx file onto the host's disk (editable afterward like any other page) instead of the
     * package trying to inject an un-editable component from node_modules.
     */
    protected function bootOperatorShell(): void
    {
        if (! config('beam.accounts.operator_shell.enabled', true)) {
            return;
        }

        if (Route::has('operator.home')) {
            return;
        }

        $this->publishes([
            __DIR__.'/../stubs/js/pages/operator/dashboard.tsx' => resource_path('js/pages/operator/dashboard.tsx'),
        ], 'beam-accounts-operator-shell');

        Route::middleware(['web', 'auth', 'can:entitlement:os.operate'])
            ->get('/operator', function (Request $request) {
                $user = $request->user();
                $model = BeamAccounts::userModel();
                $props = [
                    'staff' => ['name' => $user->name, 'email' => $user->email],
                    'stats' => ['users' => $model::count()],
                ];

                // Opaque by default: no host file required at all, server-rendered Blade, always
                // available the moment the package is installed. The moment a host publishes (or
                // hand-authors) resources/js/pages/operator/dashboard.tsx — ejecting into a real,
                // editable Inertia page — this prefers THAT instead, with no route change needed.
                if (is_file(resource_path('js/pages/operator/dashboard.tsx'))) {
                    return Inertia::render('operator/dashboard', $props);
                }

                return view('beam-accounts::operator-shell', $props);
            })
            ->name('operator.home');
    }

    /**
     * The account + team-admin FRAME RESOURCES (Frame OS ticket 20) — the OOTB list/detail surfaces a
     * host gets by installing beam-accounts: Tokens (list + revoke), Invitations (list + create + revoke),
     * Members (list-only). Two are attribute-declared `#[ParticleResource]` DTOs; Members is SOURCE-backed
     * (a pivot-backed list), registered imperatively as a ParticleResource (the attribute
     * can't express it). One `register()`/`registerDefinition()` call per resource is enough for BOTH the
     * REST transport and Frame's manifest — beam's merged {@see ParticleResourceRegistry} serves both off
     * the one stored declaration (the retired `AdminResourceRegistry` used to need each resource registered
     * TWICE, once per registry; that split is gone).
     *
     * **Always registers — there is no host off-switch, deliberately.**
     * {@see ParticleResourceRegistry} keys by resource key and the LAST registration wins, so a host
     * that curates its own roster (e.g. splicewire-app, which registers tenant-scoped variants of
     * tokens/invitations/members) overrides simply by registering after this package. App providers
     * boot after auto-discovered package providers, so that is the default outcome, not a race.
     *
     * A host that wants certainty lists the providers explicitly in `config/app.php` (preferred), or
     * defers its own registration to an `$app->booted()` callback — the latter only works while
     * exactly one party defers.
     *
     * Still inert unless beam's particle registry is present — that guard is structural, not policy.
     *
     * **Registers DIRECTLY, not through `afterResolving` (particle-contribution-seam ticket 07).** This
     * method used to wrap the whole body in `$app->afterResolving(ParticleResourceRegistry::class, …)` on
     * the reasoning that it made the beam↔beam-accounts boot order irrelevant. It did the opposite: beam
     * resolves that singleton in its OWN `packageBooted()`, and Laravel returns a cached singleton without
     * firing resolving callbacks, so the hook never ran and all five declarations below were silently
     * absent in every host measured. The direct call needs no hook to be order-safe — beam BINDS the
     * registry in the register phase, and Laravel runs `register()` on every provider before `boot()` on
     * any, so `bound()` is already true here whatever the provider order.
     * {@see \Splicewire\Beam\Particle\DeadResolvingHookGuard}
     * now throws if anyone re-introduces the hook.
     */
    protected function bootFrameResources(): void
    {
        // Inert unless beam's particle registry is present (a beam-less host gets nothing).
        if (
            ! class_exists(ParticleResourceRegistry::class)
            || ! class_exists(AttributedParticleDiscovery::class)
            || ! $this->app->bound(ParticleResourceRegistry::class)
        ) {
            return;
        }

        $registry = $this->app->make(ParticleResourceRegistry::class);

        foreach ([TokenData::class, InvitationData::class, TeamData::class, UserData::class] as $dataClass) {
            $registry->register(AttributedParticleDiscovery::resourceFromAttribute($dataClass));
        }

        // Members — backed by the team pivot rather than a plain model, so it is declared
        // imperatively (the attribute has nowhere to put a backing class). Mirrors tower's
        // TowerFrameResourceProvider.
        //
        // No `BacksModel` on the backing, deliberately: a seat is a pivot row and no single model
        // identifies it. That used to be spelled `model: null`, which only worked because frame's
        // declaration type allowed a null there and beam's did not — the merge blocker ticket 11 §A10
        // named. With the model field gone there is nothing to null out.
        //
        // ⚠️ `members` is registered by TWO packages: this one and tower. Both were raw definitions
        // before, both are ParticleResources now, and the registry is still last-wins by key — so
        // whichever provider boots later still wins. The merge did not create that collision and does
        // not resolve it; it is recorded on the map for ticket 15.
        $registry->register(new ParticleResource(
            key: 'members',
            backing: MembershipSource::class,
            data: MembershipData::class,
            filterable: false,
            form: 'bare',
            label: 'Members',
            group: 'Settings',
            icon: 'users',
            readOnly: true,
            deletable: false,
            editable: false,
        ));
    }

    /**
     * Register `me` — the caller's own identity projection, as a particle resource
     * (particle-contribution-seam 16/18).
     *
     * ## Why it is a resource at all
     *
     * So that a package which owns a concern can add its slice of it. `entitlements` (a beam-commerce
     * concept) and `platformEmbedPk` (a beam-embed one) used to reach this projection through a
     * single-slot container binding, which meant they could only meet in a host that saw both packages
     * at once — and so both were hoisted into tower, a package that owns neither. As a resource key,
     * each ships from the package that owns it and neither names the other (ticket 16 §A1).
     *
     * ## Why `me`, and not `users` or `auth-user`
     *
     * Not `users`: a second projection of one model keyed off one existing resource is the per-realm
     * overlay shape ({@see \Splicewire\Beam\Realm\RealmResourceRegistry}), which returns the base
     * unchanged whenever the realm is null — a trap this effort has walked into three times. Not
     * `auth-user`: ticket 16 measured that no such key ever existed; `/me` was a hand-written route
     * closure, which is precisely why nothing about it went through the seam.
     *
     * ## Shape
     *
     * Model-backed (the configured user model), so the contribution fold receives a real `Model` at
     * {@see \Splicewire\Beam\Http\Particle\ParticleController::projectRecord()} like every other
     * resource. `filterable: false` because there is no list — ticket 14 found the default `true` routes
     * an index through the shipped hydrator's throwing `query()`, and a singleton has no index to save.
     * `readOnly` + `frame: false`: writes are the existing `PATCH /me` profile controller's, and there is
     * no admin surface for a resource with one row per caller.
     *
     * Subject resolution is the ONE thing the generic controller cannot do here — a singleton has no
     * `{id}` — and {@see MeController} overrides exactly that, nothing else.
     */
    protected function bootMeResource(): void
    {
        // Inert unless beam's particle registry is present (a beam-less host gets nothing).
        if (
            ! class_exists(ParticleResourceRegistry::class)
            || ! $this->app->bound(ParticleResourceRegistry::class)
        ) {
            return;
        }

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: MeController::KEY,
            backing: BeamAccounts::userModel(),
            data: AuthUserData::class,
            // `AuthUserData` is not `AuthUserData::from($user)`: the identity core branches on tenancy
            // (tenant-scoped roles vs. the central tenants list) and mirrors the caller's bearer back as
            // `access_token`. `project:` is legal residue under ticket 12 §A4's rule — it does something
            // `data::from($record)` provably cannot — and the bearer is read off the live request because
            // the closure is handed only the record.
            project: fn (Model $user): AuthUserData => AuthUserData::fromUser($user, request()?->bearerToken()),
            filterable: false,
            readOnly: true,
            frame: false,
        ));
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

        // The `users` resource's write gate ({@see UserPolicy}) — needed now that the resource
        // widened `editable`. Registered against the CONFIGURED user model, since hosts routinely
        // subclass ours, and deferred to `booted()` so a host's own AuthServiceProvider has already
        // run: if the host has bound a User policy of its own, that one wins and this is skipped
        // entirely. A package must not silently replace a host's identity policy.
        $this->app->booted(function (): void {
            $model = BeamAccounts::userModel();

            if (Gate::getPolicyFor($model) === null) {
                Gate::policy($model, UserPolicy::class);
            }
        });

        // A share link is managed (revoked) by its minter (ADR-0009, tracer 05). Not
        // team-scoped like invitations — the check is minter-ownership, compared as strings
        // since created_by is a cross-host string key.
        Gate::define('manageShareLinks', function ($user, ShareLink $link) {
            return $link->created_by !== null
                && (string) $link->created_by === (string) $user->getAuthIdentifier();
        });

        $this->bootAuthoringGates();
    }

    /**
     * The bare `ux.author` / `ux.{realm}.author` Gate aliases — normalized here instead of every host
     * hand-rolling the identical pair (found byte-for-byte duplicated, docblock and all, in both
     * `audiostud`'s and `laravel-beam-starter`'s own `AppServiceProvider`, back when these were named
     * `author-ux`/`author-ux-{realm}` — renamed to the dot-cascade fleet-wide). `Gate::define()` is
     * last-write-wins by name, so a host that still defines its own version of either (e.g. to layer
     * extra logic on top) overrides this cleanly — nothing here needs a guard.
     *
     * `ux.author` reads through the `entitlement:ux.author` Gate beam-core's registerEntitlementAbilities()
     * defines (now that {@see self::registerEntitlementKeys()} lists it). `ux.{realm}.author` has no
     * `entitlement:` Gate to ride — it's realm-PARAMETERIZED, and beam-core only defines abilities for
     * the flat key list — so it reads the resolver's raw key list directly instead, over beam-core's
     * RealmRegistry (operator/tenant/site/user by default, plus any host `#[Realm]` preset).
     *
     * Deliberately does NOT fall back to `ux.author` for the per-realm check: `DefaultEntitlementResolver`
     * composes the coarse `ux.author` key as soon as ANY single realm is granted, so a
     * `ux.{realm}.author = ux.author || ...` shortcut would let a grant on just ONE realm leak authoring
     * into every OTHER realm — defeating the whole point of the per-realm grain. A grantee of every realm
     * still authors every realm (each `ux.{realm}.author` key composes independently); a narrowly-granted
     * principal now correctly stays narrow.
     */
    protected function bootAuthoringGates(): void
    {
        Gate::define('ux.author', fn ($user) => $user->can('entitlement:ux.author'));

        foreach (array_keys($this->app->make(RealmRegistry::class)->all()) as $realm) {
            $ability = "ux.{$realm}.author";

            Gate::define($ability, fn ($user) => in_array(
                $ability,
                $this->app->make(EntitlementResolver::class)->entitlementsFor($user),
                true,
            ));
        }
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
        if (! BeamDemo::enabled()) {
            return;
        }

        // The signed browser link now targets the OPERATION route (`users/{id}/op/login-as`) rather
        // than a bespoke `account/login-as/{subject}` controller. Mounted GET because a human clicks
        // it — `particleOp` takes the verb as an option precisely so a signed magic-link can be one.
        // The op is already registered (see bootFrameResources), so this mounts by bare name.
        //
        // A host wanting the JSON/API half mounts the same op as POST in its own group; the handler
        // returns AuthUserData there and a redirect here, off one declaration.
        // `particleOps` with a runtime object REGISTERS and MOUNTS in one call, so the operation
        // and its route share one demo gate — when demo is off neither exists, which is what the
        // retired bespoke route did too.
        Route::middleware('web')->group(function () {
            Route::particleOps('users', 'users', [LogInAsUser::operation()], ['method' => 'get']);
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
     * The per-host OIDC-issuer module (tenant-database-upsell ticket 16): a self-hosted
     * `/.well-known/openid-configuration` + `/.well-known/jwks.json` pair so this host can
     * prove its own identity to an OIDC-federation consumer (GCP Workload Identity Federation,
     * most immediately) with no static secret ever leaving the box. Default-off, mirroring the
     * `keys` seam — the primitives (SigningKey/IdentityTokenMinter) are always bound above;
     * only the public routes + the host-facing key-generation command are gated here.
     *
     * Registered WITHOUT the `web` middleware group deliberately: a federation consumer polls
     * this endpoint on its own schedule (GCP caches a WIF provider's JWKS but still refetches
     * periodically), and there is nothing here a session/CSRF stack needs to protect — the
     * entire point of a JWKS route is that it's safe to serve to anyone, unauthenticated.
     */
    protected function bootOidc(): void
    {
        if (! config('beam.accounts.oidc.enabled', false)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateOidcSigningKeyCommand::class]);
        }

        Route::group([], __DIR__.'/../routes/oidc.php');
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
