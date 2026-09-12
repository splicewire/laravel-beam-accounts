<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Fortify;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\Popcorn\Concerns\ChainsTraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods as ChainsTraitMethodsContract;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Accounts\Concerns\WiresApiGuard;
use Splicewire\Beam\Accounts\Concerns\WiresAuthorization;
use Splicewire\Beam\Accounts\Concerns\WiresDemo;
use Splicewire\Beam\Accounts\Concerns\WiresFortify;
use Splicewire\Beam\Accounts\Concerns\WiresFrameResources;
use Splicewire\Beam\Accounts\Concerns\WiresKeys;
use Splicewire\Beam\Accounts\Concerns\WiresMeResource;
use Splicewire\Beam\Accounts\Concerns\WiresMiddleware;
use Splicewire\Beam\Accounts\Concerns\WiresNotifyRecipients;
use Splicewire\Beam\Accounts\Concerns\WiresOidc;
use Splicewire\Beam\Accounts\Concerns\WiresOperatorShell;
use Splicewire\Beam\Accounts\Concerns\WiresRouteMacro;
use Splicewire\Beam\Accounts\Concerns\WiresRoutes;
use Splicewire\Beam\Accounts\Concerns\WiresSeed;
use Splicewire\Beam\Accounts\Concerns\WiresTeamsMigrations;
use Splicewire\Beam\Accounts\Console\LoginAsCommand;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Doctor\BeamAccountsMigrationsAudit;
use Splicewire\Beam\Accounts\Doctor\BeamAccountsRetiredMigrationAudit;
use Splicewire\Beam\Accounts\Doctor\PermissionModelPairingAudit;
use Splicewire\Beam\Accounts\Doctor\PublishGateCoverageAudit;
use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\EntitlementComposer;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Accounts\Oidc\SigningKey;
use Splicewire\Beam\Accounts\Support\NullAccountShellProvider;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Realm\RealmRegistry;

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
class BeamAccountsServiceProvider extends PackageServiceProvider implements ChainsTraitMethodsContract
{
    use ChainsTraitMethods;
    use WiresApiGuard;
    use WiresAuthorization;
    use WiresDemo;
    use WiresFortify;
    use WiresFrameResources;
    use WiresKeys;
    use WiresMeResource;
    use WiresMiddleware;
    use WiresNotifyRecipients;
    use WiresOidc;
    use WiresOperatorShell;
    use WiresRouteMacro;
    use WiresRoutes;
    use WiresSeed;
    use WiresTeamsMigrations;

    /**
     * The third value a publish gate can take, alongside `true` and `false`.
     * {@see self::estateDeclaredAbsent()} for what it means and why it is a string.
     */
    public const ESTATE_ABSENT = 'absent';

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
        // beam-docs-satellite ticket 25 and read through {@see self::publishesEstateNamed()}):
        //
        // AUTH estate — users/permission_tables/passkeys/PAT-provenance/the tenant identity
        // estate. On by default; a host would only turn this off if it owns its own auth schema
        // entirely (mirrors the old bootMigrations() split).
        //
        // TEAMS estate — teams/memberships/invitations/access-grants/view-requests,
        // now reclassified into shared/ (was its own host-placed `teams/` directory; see
        // shared/create_teams_table.php.stub's docblock for why). A host that runs its own
        // separate team system (splicewire-app) turns this off so these tables are never
        // published onto its disk at all.
        $migrations = [];

        foreach (self::gatedEstates() as $estate => $stubs) {
            if (self::publishesEstateNamed($estate)) {
                $migrations = array_merge($migrations, $stubs);
            }
        }

        $package
            ->name('laravel-beam-accounts')
            ->hasConfigFile(['beam/accounts'])
            ->hasViews('beam-accounts')
            ->hasMigrations($migrations);
    }

    /**
     * The two independently-gated publish estates, keyed by the config-key suffix that gates each.
     *
     * PUBLIC and static because {@see \Splicewire\Beam\Accounts\Doctor\PublishGateCoverageAudit} reads
     * the same lists to verify the claim a host makes by turning a gate off ("this estate is already
     * committed on my disk"). Two copies of these names would be two copies that drift, and the whole
     * point of the audit is that a second statement of the estate went stale without anyone noticing —
     * tower's `config/beam/accounts.php` docblock still describes migrations that repo deleted.
     *
     * Declared order matters and is load-bearing: creates before their alters, parents before children
     * (FKs). package-tools stamps each entry a second apart in listed order at publish time, which is
     * the only thing making `create_personal_access_tokens_table` precede its own provenance ALTER.
     *
     * @return array<string, list<string>>
     */
    public static function gatedEstates(): array
    {
        return [
            // AUTH — users/permission_tables/passkeys/PAT create+provenance/the tenant identity estate.
            'auth_migrations' => [
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
                // The ALTER immediately after its own create, for the same reason
                // `shared/add_slug_to_teams_table` sits after `shared/create_teams_table`. It exists
                // because editing the create stub to `->nullable()` reaches ONLY a fresh database and
                // `ConvergentTable::assert()` does not alter an existing column's nullability — so 17
                // of 17 flagship tenant schemas were still `NOT NULL` against a publish that was
                // already correct. See the stub's docblock.
                'tenant/relax_guest_token_landing_url_nullability',
                'tenant/create_sign_offs_table',
            ],

            // TEAMS — teams/memberships/invitations/access-grants/view-requests.
            'migrations' => [
                'shared/create_teams_table',
                // The slug ALTER sits immediately after its own create and BEFORE the child tables,
                // because it constrains the column that create declares nullable — three steps the
                // create cannot take on a populated table (beam-facade 159; see the stub's docblock).
                'shared/add_slug_to_teams_table',
                'shared/create_memberships_table',
                'shared/add_current_team_id_to_users_table',
                'shared/create_invitations_table',
                'shared/create_access_grants_table',
                'shared/create_view_requests_table',
                'shared/create_impersonation_events_table',
            ],
        ];
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
     * deliberately turned off. `splicewire-app` is exactly that host (recorded in
     * `splicewire-recohere`'s SESSION-8 ledger, which calls the flip "do NOT force"). Both default
     * true, so a host setting neither is unaffected.
     */
    public static function publishesEstateNamed(string $estate): bool
    {
        return self::closedGateKeysFor($estate) === [];
    }

    /**
     * Which config key(s) are actually holding this estate's gate shut.
     *
     * ⚠️ The gate is the AND of TWO keys — the modern `publish_*` and the legacy `register_*` spelling
     * kept for hosts that set it before beam-docs-satellite ticket 25 renamed it — so naming only the
     * modern one in a finding sends the reader to a key that is `true` at their host. That happened:
     * `beam-facade` 155 spent a section establishing that the flagship's `publish_migrations` is `true`
     * and `register_migrations` is what is false, because the FAIL text named the wrong key and an
     * agent grepping it found nothing. A finding that misnames its own subject is worse than no
     * finding, so the audit reads this rather than assuming.
     *
     * @return list<string> fully-qualified config keys, empty when the estate publishes
     */
    public static function closedGateKeysFor(string $estate): array
    {
        $closed = [];

        foreach (["publish_{$estate}", "register_{$estate}"] as $suffix) {
            $value = config("beam.accounts.{$suffix}", true);

            // `'absent'` is truthy as a string, so it has to be tested BEFORE the bool cast or the
            // third state would silently re-open the publish gate — the one bug that would make this
            // whole state worse than not having it.
            if (self::readsAbsent($value) || ! (bool) $value) {
                $closed[] = "beam.accounts.{$suffix}";
            }
        }

        return $closed;
    }

    /**
     * The THIRD state: *"this estate is deliberately not present at this host, and never will be."*
     *
     * ## Why a third state exists at all
     *
     * The gate was two-state, and both readings assert the tables will exist: `true` = "publish them
     * onto my disk", `false` = "they are already committed on my disk". {@see
     * \Splicewire\Beam\Accounts\Doctor\PublishGateCoverageAudit} checks the second, which is the whole
     * point of it — but there is a real host it has no vocabulary for. `splicewire-app` runs its own
     * team system over `tenant_users` (approach B: no table unification, no data migration) and the
     * engine's `beam_teams`/`beam_memberships` **must never be created there**. It has said so in its
     * own config docblock since before this audit existed, and the audit read that decision as the
     * tower outage and told it to do one of two things it must not do.
     *
     * So the estate's own idiom applies — an omission and a decision must not be spelled identically
     * ({@see \Splicewire\Beam\Particle\ParticleOperation}'s `ability:`/`input:`/`abilityModel:`, all
     * three-state for exactly this reason). One slot, three values:
     *
     *   - **`true`** (default) — publish this estate's stubs onto the host's disk;
     *   - **`false`** — do not publish, because every member is ALREADY COMMITTED here. A claim, and
     *     the audit fails the host that gets it wrong;
     *   - **`'absent'`** — do not publish, because this estate has no place at this host. The audit
     *     reads it as satisfied.
     *
     * ## Why a string, and why this string
     *
     * The third state must be **unreachable by omission**, because "deliberately absent" and "nobody
     * has looked yet" meaning the same thing is precisely the defect being fixed. Every accidental
     * route lands somewhere else:
     *
     *   - key not set at all → `config(…, true)` → publishes (unchanged);
     *   - `null`, `''`, `'0'`, a missing `.env` var → falsy → the AUDITED `false` claim, unchanged, so
     *     a typo still fails loudly rather than muting the check;
     *   - a pre-rename host carrying `register_* => false` → still the audited claim, unchanged.
     *
     * Only a host that typed the word gets the third state. Matched case-insensitively and trimmed
     * because it also arrives through `env('ACCOUNT_PUBLISH_MIGRATIONS')`, where the value is whatever
     * a human put after the `=`.
     *
     * ⚠️ And absence is a claim too, so it is also checked — weakly, and deliberately so: the audit
     * WARNS (never fails) when a host declaring an estate absent has members of it committed anyway,
     * because a stem collision there is a fact about the host, not about the declaration. See the
     * audit's docblock.
     */
    public static function estateDeclaredAbsent(string $estate): bool
    {
        foreach (["publish_{$estate}", "register_{$estate}"] as $suffix) {
            if (self::readsAbsent(config("beam.accounts.{$suffix}", true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this host CARRIES an estate — the question a consumer of the estate's *tables* asks,
     * which is not the question {@see self::publishesEstateNamed()} answers.
     *
     * Of the gate's three values only one declines the estate. `true` publishes the stubs and `false`
     * claims *"every member is ALREADY COMMITTED here"* — both assert the tables will exist, and the
     * publish audit exists precisely to hold a host to the second claim. Only `'absent'` says the
     * estate has no place at this host.
     *
     * So a reader that needs "are these tables part of this host" must NOT read `publishesEstateNamed()`:
     * that collapses `false` into `'absent'` and treats a host that committed the migrations itself as
     * a host that declined them. {@see \Splicewire\Beam\Accounts\Concerns\WiresSeed} did exactly that
     * and silently stopped seeding `laravel-tower-starter`, whose gate is `false` for the ordinary
     * reason (ux-demo-convergence `G1-TOWER-FRESH-INSTALL`, measured 2026-09-12 on a fresh tower
     * checkout: the tables were migrated, the demo gate was on, and no demo team was written).
     *
     * ⚠️ This is a DECLARATION, not an observation: it reads config and never touches the database,
     * because its callers run in the provider's `boot` where no connection is guaranteed and a schema
     * query would land on every request. A caller that needs the table to actually be there asks
     * `Schema::hasTable()` at the point of use — see {@see Database\Seeders\RolePermissionsSeeder} and
     * {@see Database\Seeders\DemoTeamSeeder}, which each skip themselves with a message instead.
     */
    public static function estateDeclaredPresent(string $estate): bool
    {
        return ! self::estateDeclaredAbsent($estate);
    }

    protected static function readsAbsent(mixed $value): bool
    {
        return is_string($value) && strtolower(trim($value)) === self::ESTATE_ABSENT;
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
        // The `central` connection alias is NOT registered here any more (beam-facade ticket 96) —
        // it lives in {@see \Splicewire\Beam\BeamServiceProvider::registerCentralConnectionAlias()},
        // which this package requires, so {@see Models\User}'s pin is covered exactly as before.
        // The ruling: the tier that DECLARES a pin owns making it resolvable, and core declares one
        // of its own (`CentralActivityLog`) that no amount of registration HERE could ever reach —
        // five Herd hosts install beam-core without beam-accounts and threw on it. Read the move's
        // full argument off core's docblock rather than re-deriving it.

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

        $this->app->singleton(TeamProvisioner::class);

        // The declarative entitlement-bundle layer (Frame OS ticket 09): the BundleRegistry reads the
        // host-declared `name => keys` bundles from config; the EntitlementComposer folds a bundle
        // baseline with per-principal grants/denies (`plan-baseline ∪ grants − denies`). Both are pure
        // — no plan model — so a host's bound EntitlementResolver composes them over its own plan/grant
        // discovery. Singletons so the resolved config is read once per request.
        // A `ConfigRegistry` since registry-kernel 38: its storage IS
        // `config('beam.accounts.entitlements.bundles')`, read through on every read, so a bundle
        // declared after this binding resolves is still visible. Container-constructed so the
        // config Repository is injected.
        $this->app->singleton(BundleRegistry::class);
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
        // Every concern this package boots, contributed by the trait that OWNS it rather than
        // hand-listed here. Each link declares its own `order:`, so adding a concern is `use`-ing a
        // trait — and the sequence does not rest on where a `use` statement sits, which `pint`'s
        // `ordered_traits` fixer resorts alphabetically.
        $this->chainTraitMethods('boot');

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

            // Verifies the CLAIM a host makes by turning a publish gate off — that the estate is
            // already committed on its disk. Tower asserted it, deleted two of the files, and nothing
            // noticed until a fresh clone could not migrate at all (beam-docs-satellite ticket 25).
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-accounts',
                PublishGateCoverageAudit::class,
            );

            // The eight stubs `4272fbb` retired out of `teams/`. Publishing is a COPY, so every host
            // installed before it still carries them — measured at `~/Herd/splicewire`, all eight.
            // See {@see BeamAccountsRetiredMigrationAudit} for why the ALTER is declared only under
            // `teams/` and never as a bare stem.
            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-accounts',
                BeamAccountsRetiredMigrationAudit::class,
            );

            // The uuid permission schema and the model that can key it are one decision, and this
            // package ships both halves while binding neither — deliberately (ticket 98). Nothing in
            // the estate stated that pairing, checked it, or failed on it until this audit
            // (beam-facade 141). Note the shape it catches that no check below this tier can: the
            // hosts on the INTEGER schema are self-consistent and working, and the stale-snapshot
            // repair AGENTS.md prescribes for them is precisely what breaks them.
            $this->app->bind(PermissionModelPairingAudit::class, fn () => PermissionModelPairingAudit::forApp());

            $this->app->make(BeamDoctorManifest::class)->register(
                'splicewire/laravel-beam-accounts',
                PermissionModelPairingAudit::class,
            );
        }
    }
}
