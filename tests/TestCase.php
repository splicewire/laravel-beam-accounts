<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Sluggable\SluggableServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Models\Permission;
use Splicewire\Beam\Accounts\Models\Role;
use Splicewire\Beam\Accounts\Tests\Fixtures\FixtureRealmGrantable;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Notifications\BeamNotificationsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Whether the test app configures `permission.models.*` onto this package's uuid-keyed pair.
     *
     * True models a real beam host; the ONE test class that measures what an *unconfigured* app
     * resolves ({@see UuidKeyedPermissionModelsTest}) turns it off. See
     * {@see defineEnvironment()} for why the default is on.
     */
    protected bool $bindBeamPermissionModels = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aliasCentralOntoTheDefaultDatabase();

        $this->createUsersSchema();
        $this->createTeamsSchema();
        $this->createSpatieSchema();
    }

    protected function getPackageProviders($app): array
    {
        return [
            // popcorn, FIRST — it binds the shared `RegistryIndex` singleton. Testbench does not
            // auto-discover, and requiring the package does not fix it: without this line every
            // `make(RegistryIndex::class)` builds a THROWAWAY, so every `describe()` lands on an
            // index nothing else can see and the suite stays green over nothing (registry-kernel
            // 27 D3). {@see RegistryConformanceTest} carries the tripwire.
            PopcornServiceProvider::class,
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            FortifyServiceProvider::class,
            // laravel-data, so an input DTO injected as a controller parameter hydrates from the
            // request instead of the container trying to construct it positionally. A real host gets
            // this by package auto-discovery; testbench does not, which is why it must be listed.
            // Its absence went unnoticed because no test exercised an injected input DTO — the
            // Api\V1\ProfileController has taken one since HTTP-07 with no coverage behind it.
            LaravelDataServiceProvider::class,
            // spatie/laravel-sluggable, for `Team`'s HasSlug (beam-facade 159). Testbench does not
            // auto-discover, and that package's provider registers the `actions.generate_slug` map
            // HasSlug resolves through — without it every Team::create() dies "No action class is
            // configured for key `generate_slug`", which is beam-facade 137's symptom one package over.
            //
            // The provider is a v4 class; v3 has no action registry and HasSlug needs no provider
            // there. The composer require stays `^3.5|^4.0` to match `splicewire/laravel-beam` and not
            // force an upgrade on the 3 estate roots still resolving v3 (measured 2026-08-27: 39 roots
            // on 4.0.3, 3 on 3.x), so this line is a statement about the DEV lock, not about the
            // package's floor. A v3 dev lock fatals here by name, which is the right kind of loud.
            SluggableServiceProvider::class,
            // beam-core, so its BeamSeedManifest singleton binds — beam-accounts registers its
            // DemoTeamSeeder into it (bootSeed). beam-accounts hard-deps beam-core in composition.
            BeamServiceProvider::class,
            BeamAccountsServiceProvider::class,
            // beam-notifications, so this package's `to_roles:` / `to_teams:` recipient kinds are
            // exercised against the real notify package rather than against half of themselves
            // (beam-facade 159). It is a `require-dev` and a `suggest`, never a `require` — 100 D4
            // ruled the coupling stays soft, because a hard require drags stancl/tenancy into two
            // single-tenant sites and doubles the estate's uuid-permission danger set.
            //
            // Order matters and is the reason this is a provider entry rather than a late
            // `app()->register()`: `mergeConfigFrom` is a shallow top-level merge, so the notify
            // package's config must be merged during the register pass, before this package's boot
            // chain appends its two kinds to it.
            BeamNotificationsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('auth.providers.users.model', User::class);
        $config->set('permission-cascade.user_model', User::class);

        // ACC-01: the OOTB realm-root port binding under test — beam-ux's own binding is the same
        // shape (BeamUxEntry instead of the RealmRoot fixture), exercised in beam-ux's own suite.
        $config->set('beam.accounts.entitlements.realm_grantable', FixtureRealmGrantable::class);

        // The host's one semantic config delta, mirrored here rather than left to a comment
        // (beam-facade 138; the same shape api-surface-coherence 84 had to add for laravel-data).
        //
        // `createSpatieSchema()` now builds the uuid-keyed `roles`/`permissions` this package's own
        // stub ships, and stock Spatie's Role/Permission generate no key — so `findOrCreate()` dies
        // on NOT NULL, which is the entire reason `Models\Role`/`Models\Permission` exist (98). The
        // provider deliberately does NOT default this config (98 again: three hosts — audiostud,
        // numero, fable — run stock Spatie over an older bigIncrements publish, and defaulting it
        // would push uuid strings at their live bigint keys). So the pair is a HOST-side wiring
        // requirement, and a harness that ships the uuid schema without it is modelling a host that
        // does not exist.
        //
        // Measured 2026-08-26, every `~/Herd/*` and starter carrying `config/permission.php`: every
        // host on the uuid schema configures a uuid-capable Role — `~/Herd/beam`, `~/Herd/tower` and
        // `~/Herd/satellite` extend THIS pair (`App\Models\Role extends BeamAccountsRole`),
        // `~/Herd/splicewire` hand-rolls the same `HasUuids`, `~/Herd/splicewire-app` uses
        // `Splicewire\Tower\Models\Role`. The three stock-Spatie hosts are exactly the three on the
        // integer-keyed publish. There is no host with uuid tables and an unbound model — which is
        // also the finding beam-facade 141 was opened for, since nothing enforces that pairing.
        if ($this->bindBeamPermissionModels) {
            $config->set('permission.models.role', Role::class);
            $config->set('permission.models.permission', Permission::class);
        }

        $config->set('session.driver', 'array');

        // laravel-data's package config isn't merged under Testbench, so its defaults come through
        // null/absent and trip a TypeError when a Data class is transformed (->toArray) or hydrated
        // (::from). Load the package's full default config so both work in the isolated test app.
        $config->set('data', require dirname(__DIR__).'/vendor/spatie/laravel-data/config/data.php');
        $config->set('data.max_transformation_depth', null);
        $config->set('data.throw_when_max_transformation_depth_reached', true);

        $config->set('fortify.guard', 'web');
        $config->set('fortify.home', '/');
        $config->set('fortify.views', false);
        $config->set('fortify.features', [
            Features::registration(),
            Features::resetPasswords(),
            Features::emailVerification(),
            Features::updatePasswords(),
        ]);
    }

    /**
     * Make the `central` connection reach the SAME database as the default one.
     *
     * `BeamServiceProvider::registerCentralConnectionAlias()` registers `central` as a COPY of the
     * default connection's config, which is correct everywhere except here: this harness's default
     * is `:memory:`, and two sqlite connections both configured as `:memory:` are two SEPARATE
     * databases. {@see CentralConnectionAliasTest} already says so in its own docblock, and pays a
     * file-backed sqlite to avoid it.
     *
     * That divergence was not cosmetic — it was hiding an entire HTTP surface. Every schema this
     * class builds lands on the default connection, while {@see \Splicewire\Beam\Accounts\Models\User}
     * (the `backing:` of the `users` particle resource, and therefore what
     * `RecordSubject`/`ResourceRecordLookup` resolve an operation's `{id}` through) is PINNED to
     * `central`. So every request that resolved a user through the resource died on
     * `no such table: users` before it reached anything under test: all six HTTP cases in
     * {@see \Tests\DemoLoginAsTest} — the login-as redirect, the 404, the unsigned refusal, the
     * tampered refusal, the expiry refusal and the demo-disabled 403 — had been red for their whole
     * life, which is precisely why beam-facade 172's binding defect shipped with a suite over it.
     * The estate's recurring shape: an instrument that reports failure by not running.
     *
     * Sharing the PDO instance rather than re-pointing the config is what actually joins two
     * in-memory databases — a second `:memory:` DSN opens a third one. A real host's `central` is an
     * ordinary shared database, so this models the host rather than excusing it.
     */
    protected function aliasCentralOntoTheDefaultDatabase(): void
    {
        if (! array_key_exists('central', config('database.connections', []))) {
            return;
        }

        DB::connection('central')->setPdo(DB::connection()->getPdo());
    }

    protected function createUsersSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function createTeamsSchema(): void
    {
        Schema::create(Beam::table('teams'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            // The shape a host has AFTER `shared/add_slug_to_teams_table` — NOT NULL and globally
            // unique, which is what the model's HasSlug is written against (beam-facade 159). The
            // three-step nullable/backfill/constrain path that gets a POPULATED host here is exercised
            // by TeamSlugMigrationTest against the real stub, not modelled by this fixture.
            $table->string('slug')->unique();
            $table->boolean('personal_team')->default(false);
            $table->timestamps();
        });

        Schema::create(Beam::table('memberships'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create(Beam::table('invitations'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('email');
            $table->string('role')->default('member');
            $table->string('token')->unique();
            $table->timestamps();
            $table->unique(['team_id', 'email']);
        });

        Schema::create(Beam::table('access_grants'), function (Blueprint $table): void {
            $table->id();
            $table->string('grantable_type');
            $table->string('grantable_id');
            $table->string('grantee_type');
            $table->string('grantee_id');
            $table->string('ability');
            $table->string('effect');
            $table->timestamps();
        });

        Schema::create(Beam::table('visibilities'), function (Blueprint $table): void {
            $table->id();
            $table->string('reachable_type');
            $table->string('reachable_id');
            $table->string('tier')->nullable();
            $table->boolean('listed')->nullable();
            $table->timestamps();
            $table->unique(['reachable_type', 'reachable_id']);
        });

        Schema::create(Beam::table('view_requests'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('requestable_type');
            $table->string('requestable_id');
            $table->string('requester_type');
            $table->string('requester_id');
            $table->string('status')->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // A HasVisibility fixture to share (steward via HasUserId).
        Schema::create('shareables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('visibility')->nullable();
            $table->timestamps();
        });

        // A HasVisibility "realm root" fixture (ACC-01) — stands in for beam-ux's BeamUxEntry so
        // DefaultEntitlementResolver's grant cascade is exercised without a beam-ux dependency.
        Schema::create('realm_roots', function (Blueprint $table): void {
            $table->id();
            $table->string('realm')->unique();
            $table->string('visibility')->nullable();
            $table->timestamps();
        });
    }

    /**
     * The Spatie permission fixture, keyed the way this package's OWN
     * `database/migrations/shared/create_permission_tables.php.stub` keys it (beam-facade 138).
     *
     * `roles.id` and `permissions.id` are **uuid**, unconditionally, because the stub declares them
     * that way unconditionally — there is no host shape and no config flag under which this package
     * creates an integer-keyed `roles`. Until 138 this helper built `$table->id()`, so all 230-odd
     * tests ran against a schema the package never ships, and
     * {@see \Splicewire\Beam\Accounts\Teams\TeamProvisioner::syncSpatieRole()} was green for its
     * whole life without once meeting the NOT NULL that {@see \Splicewire\Beam\Accounts\Models\Role}
     * exists to satisfy.
     *
     * `model_id` (spatie's `model_morph_key`) is the one column deliberately NOT converged, and that
     * is the stub's own instruction rather than an exception to it: the stub's docblock says the
     * morph key "MUST MATCH THE HOLDER'S KEY TYPE ... matching, not widening", and
     * `create_users_table.php.stub` is a *quiet terminal* that leaves a pre-existing bigint-keyed
     * host `users` alone. {@see createUsersSchema()} builds exactly that bigint-keyed holder, so a
     * bigint `model_id` here IS the stub's shape for this fixture's population. Flip the holder and
     * this column has to flip with it — which is why
     * {@see \Splicewire\Beam\Accounts\Tests\PermissionFixtureMatchesShippedStubTest} names it as the
     * single declared divergence instead of leaving it implicit.
     *
     * That test is the mechanical relation the hand-written/shipped pair had none of: it executes
     * this very stub into a scratch schema and diffs the column types. Change either side alone and
     * it goes red.
     */
    protected function createSpatieSchema(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('team_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->uuid('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
            $table->primary(['team_id', 'permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->uuid('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->string('team_id')->nullable();
            $table->index(['model_id', 'model_type']);
            $table->primary(['team_id', 'role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->uuid('permission_id');
            $table->uuid('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
    }
}
