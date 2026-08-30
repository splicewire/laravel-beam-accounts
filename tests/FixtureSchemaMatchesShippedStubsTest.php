<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * The mechanical relation between {@see TestCase}'s hand-built fixture schema and the migration
 * stubs this package actually ships — for EVERY table the two have in common, not one table at a
 * time.
 *
 * ## Why this exists at all
 *
 * This package's harness never runs its own migrations. {@see TestCase::setUp()} hand-builds the
 * schema with ~15 `Schema::create()` calls, so the fixture is the ONLY model of the database the
 * suite ever sees. A fixture that drifts from the shipped stubs does not go red anywhere — it
 * quietly validates a shape no host has, in both directions: the stub can change and the suite stays
 * green, the fixture can change and the suite stays green.
 *
 * That is not hypothetical. Its two single-table predecessors were each opened by a real incident
 * and each found drift the moment they ran:
 *
 *  - `PermissionFixtureMatchesShippedStubTest` (beam-facade 138) — the fixture built integer-keyed
 *    `roles`/`permissions` while the stub had shipped uuid keys for its whole life, so
 *    {@see \Splicewire\Beam\Accounts\Teams\TeamProvisioner::syncSpatieRole()} never once met the
 *    NOT NULL that {@see \Splicewire\Beam\Accounts\Models\Role} exists to satisfy.
 *  - `PersonalAccessTokenFixtureMatchesShippedStubTest` (`9b90180`) — `baaf6dd` had changed the
 *    create stub's `morphs('tokenable')` to `uuidMorphs('tokenable')` and nothing in the suite could
 *    see it; on its first run it also caught `name`, a `text` in the stub and a `string` in the
 *    fixture.
 *
 * Both are FOLDED INTO THIS FILE. Seven more table pairs were unguarded, and copying a
 * single-table test seven more times is the Duplicated Code that mechanism invites. Everything
 * those two files asserted is asserted here — the uuid morph on `personal_access_tokens`
 * ({@see self::DECLARED_DIVERGENCES}, pinned on both sides), the uuid key on `roles`/`permissions`
 * ({@see self::test_the_shipped_stubs_key_roles_and_permissions_by_uuid()}), and the column diff
 * itself, now generalized.
 *
 * ## How
 *
 * It **executes the shipped stubs** — in the order
 * {@see BeamAccountsServiceProvider::gatedEstates()} declares (creates before their alters, parents
 * before children), plus any stub on disk that manifest does not list — onto a second, throwaway
 * in-memory connection, under the real table names, so they run exactly as a host runs them. Then it
 * DISCOVERS the pairs (tables the probe and the fixture both hold) and diffs column type +
 * nullability, in both directions.
 *
 * A second connection rather than a prefixed set on the same one: the stubs name their indexes and
 * sqlite index names are database-global, so a same-connection run collides with the fixture's own
 * index names before it reaches an assertion.
 *
 * ⚠️ sqlite is dynamically typed, so every type claim here is read off the SCHEMA through
 * `Schema::getColumns()`. A successful insert proves nothing about a column's declared type.
 *
 * ## Scope, stated rather than left to be inferred
 *
 * Compares COLUMN TYPE and NULLABILITY. Not string lengths (sqlite's introspection does not carry
 * them), not indexes, not primary-key composition — the permission stub's pivot key shape is
 * `$teams`-conditional and the fixture pins one branch of it, which is a fixture choice rather than
 * drift.
 *
 * NOT IN SCOPE, deliberately: the hand-built tables that model a DIFFERENT host population on
 * purpose — {@see KeysModuleEnabledTest} and `DeterministicTokenTest`'s two
 * `personal_access_tokens` (a `string` tokenable_id, a uuid `id`), because the deterministic
 * minter's whole claim is that it is indifferent to the host's key shape, and
 * {@see TeamResolverSeamTest}'s own pivot. Converging those would delete the thing they test. Only
 * the base harness's schema is pinned, because only it is what the other ~40 test files run on.
 */
class FixtureSchemaMatchesShippedStubsTest extends TestCase
{
    /**
     * The throwaway connection the stubs are executed onto.
     */
    private const PROBE = 'stub_probe';

    /**
     * Every table the harness and the shipped stubs BOTH build — asserted, not discovered-and-trusted.
     *
     * ⚠️ A discovery-driven test that discovers nothing passes vacuously, which is this estate's
     * signature defect. The discovery step is therefore itself under test: this list is compared for
     * identity, so a stub that stops creating its table, a renamed fixture table, or a probe run that
     * silently failed halfway all go red here instead of quietly shrinking the diff below to nothing.
     *
     * Adding a table to both sides is a deliberate one-line edit here. That is the point.
     */
    private const EXPECTED_PAIRS = [
        'beam_access_grants',
        'beam_invitations',
        'beam_memberships',
        'beam_teams',
        'beam_view_requests',
        'beam_visibilities',
        'model_has_permissions',
        'model_has_roles',
        'permissions',
        'personal_access_tokens',
        'role_has_permissions',
        'roles',
        'users',
    ];

    /**
     * The columns the fixture deliberately does NOT take from the stub, each pinned on BOTH sides.
     *
     * A declared divergence is data with a reason, never a silent skip — and never a one-sided
     * exemption either. Each entry states the shape the STUB must have and the shape the FIXTURE
     * must have, so converging either side is red just as diverging further is. Exempting only the
     * fixture would let a stub quietly revert (which is exactly what `baaf6dd` did, invisibly).
     *
     * Every entry below descends from ONE fact: `shared/create_users_table.php.stub` is a **quiet
     * terminal**. `users` is the one table this package ADOPTS rather than owns, so that stub
     * converges what it can and writes nothing at all when it cannot — a pre-existing bigint-keyed
     * host `users` (any `laravel new` derivative) is a legitimate shape, not an install-time stop.
     * {@see TestCase::createUsersSchema()} builds exactly that holder, on purpose, because it is the
     * population the estate actually has: `~/Herd/audiostud`, `~/Herd/fable` and `~/Herd/numero` are
     * all on the integer-keyed publish.
     *
     * From that, every morph and foreign key pointing AT `users` must be bigint here too — the
     * permission stub's own docblock states the rule: a `model_morph_key` "MUST MATCH THE HOLDER'S
     * KEY TYPE ... matching, not widening." Flip the holder and all of these flip with it, which is
     * why they are named rather than left implicit.
     *
     * @var array<string, array{stub: string, fixture: string, why: string}>
     */
    private const DECLARED_DIVERGENCES = [
        'users.id' => [
            'stub' => 'varchar not-null',
            'fixture' => 'integer not-null',
            'why' => 'The quiet terminal itself. The stub keys a GREENFIELD `users` by uuid; this harness '
                .'models the pre-existing bigint-keyed host table that stub deliberately leaves alone.',
        ],
        'users.name' => [
            'stub' => 'varchar not-null',
            'fixture' => 'varchar nullable',
            'why' => 'Same quiet terminal: on this harness `users` is the HOST\'s table, so the stub\'s '
                .'define() block is not a claim about its nullability. Only the stub\'s two unconditional '
                .'retrofits — `google_id` here and `current_team_id` from add_current_team_id_to_users_table '
                .'— land on a bigint host, and both ARE compared.',
        ],
        'users.password' => [
            'stub' => 'varchar not-null',
            'fixture' => 'varchar nullable',
            'why' => 'As `users.name`. Passwordless holders (passkey, OIDC, demo login-as) are exercised '
                .'across this suite, so the fixture is nullable by intent, not by drift.',
        ],
        'personal_access_tokens.tokenable_id' => [
            'stub' => 'varchar not-null',
            'fixture' => 'integer not-null',
            'why' => 'The morph `baaf6dd` fixed. The stub says `uuidMorphs` because the holder it assumes is '
                .'the stub-created uuid `users`; a bigint morph could never hold that key. The fixture\'s '
                .'holder is bigint, so bigint here IS the stub\'s instruction for this population.',
        ],
        'model_has_roles.model_id' => [
            'stub' => 'varchar not-null',
            'fixture' => 'integer not-null',
            'why' => 'Spatie\'s `model_morph_key`, matching this harness\'s bigint holder — the permission '
                .'stub\'s docblock is the authority (beam-facade 138).',
        ],
        'model_has_permissions.model_id' => [
            'stub' => 'varchar not-null',
            'fixture' => 'integer not-null',
            'why' => 'As `model_has_roles.model_id`.',
        ],
        'beam_teams.user_id' => [
            'stub' => 'varchar not-null',
            'fixture' => 'integer not-null',
            'why' => 'The team OWNER — a foreign key at `users.id`, uuid in the stub for the same reason and '
                .'bigint here for the same reason. `key-type.convention.md`: a table\'s key type, its '
                .'foreign keys and its model must all say the same thing.',
        ],
        'beam_memberships.user_id' => [
            'stub' => 'varchar not-null',
            'fixture' => 'integer not-null',
            'why' => 'As `beam_teams.user_id`. Note `team_id` is NOT here: it points at `beam_teams.id`, '
                .'which really is `$table->id()` on both sides.',
        ],
        'beam_invitations.invited_by' => [
            'stub' => 'varchar nullable',
            'fixture' => 'integer nullable',
            'why' => 'The inviting USER, so it follows the holder like the rest. `AccountResourcesTest` had '
                .'been adding this column bigint in its own beforeEach; the harness now owns it, at the '
                .'same width.',
        ],
    ];

    /**
     * A floor on how many columns the diff actually compared, so a collapse of the mechanism cannot
     * report success by not running. 81 is what the stubs declare today across the 13 pairs, less the
     * nine declared divergences; this catches a collapse, not a deliberate addition.
     */
    private const COMPARED_FLOOR = 81;

    /**
     * The base harness deliberately does not build `personal_access_tokens` (it is Sanctum's,
     * adopted rather than owned), so this class builds it through the very helper it is pinning —
     * never a local copy, which would put another hand-written schema in the file whose job is to
     * have none.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createPersonalAccessTokensSchema();
    }

    /**
     * The guard on the guard. If discovery breaks, every other assertion in this file becomes
     * vacuous — so discovery is measured against an explicit list before anything is diffed.
     */
    public function test_discovery_finds_exactly_the_tables_the_harness_and_the_stubs_both_build(): void
    {
        $pairs = $this->discoverPairs();

        $this->assertSame(
            self::EXPECTED_PAIRS,
            $pairs,
            'The set of tables built by BOTH the test harness and the shipped stubs has changed. If that is '
            .'deliberate, add or remove the name in EXPECTED_PAIRS; if it is not, a stub stopped creating its '
            .'table or the probe run failed partway.'
        );

        $this->assertCount(13, $pairs, 'The stub/fixture pair count is no longer 13.');
    }

    public function test_every_shared_column_carries_the_shape_the_shipped_stubs_declare(): void
    {
        $pairs = $this->discoverPairs();
        $compared = 0;

        foreach ($pairs as $table) {
            $stub = $this->columns($table, self::PROBE);
            $fixture = $this->columns($table);

            $this->assertNotSame([], $stub, "The shipped stubs created no columns on `{$table}`.");
            $this->assertNotSame([], $fixture, "The test harness built no columns on `{$table}`.");

            foreach ($stub as $column => $shape) {
                if (isset(self::DECLARED_DIVERGENCES["{$table}.{$column}"])) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $column,
                    $fixture,
                    "`{$table}.{$column}` is shipped by this package's stubs and missing from the test "
                    .'fixture — the suite is running on a shape no host has.'
                );

                $this->assertSame(
                    $shape,
                    $fixture[$column],
                    "`{$table}.{$column}` is `{$shape}` in the package's shipped stubs and `{$fixture[$column]}` "
                    .'in the test fixture.'
                );

                $compared++;
            }

            // The other direction: a column the fixture has and no stub ships is a column no host
            // will ever have, so a test relying on it is testing nothing real.
            foreach (array_keys($fixture) as $column) {
                $this->assertArrayHasKey(
                    $column,
                    $stub,
                    "`{$table}.{$column}` is in the test fixture and shipped by no stub — no host has this column."
                );
            }
        }

        $this->assertGreaterThanOrEqual(
            self::COMPARED_FLOOR,
            $compared,
            "The stub/fixture diff compared only {$compared} columns — the mechanism has collapsed."
        );
    }

    /**
     * Every declared divergence pinned on BOTH sides.
     *
     * A one-sided exemption is how `baaf6dd` went unseen: the fixture was excused and the stub was
     * free to revert. Converging the fixture to the stub is red here too, because that would break
     * the bigint-holder population this harness exists to model.
     */
    public function test_every_declared_divergence_is_pinned_on_both_sides(): void
    {
        $this->discoverPairs();

        foreach (self::DECLARED_DIVERGENCES as $key => $pin) {
            [$table, $column] = explode('.', $key, 2);

            $this->assertSame(
                $pin['stub'],
                $this->columns($table, self::PROBE)[$column] ?? null,
                "`{$key}` is a DECLARED divergence and the shipped stub's side of it has moved. Why it is "
                ."declared: {$pin['why']}"
            );

            $this->assertSame(
                $pin['fixture'],
                $this->columns($table)[$column] ?? null,
                "`{$key}` is a DECLARED divergence and the fixture's side of it has moved. Why it is "
                ."declared: {$pin['why']}"
            );
        }

        $this->assertCount(9, self::DECLARED_DIVERGENCES, 'The declared-divergence list has changed size.');
    }

    /**
     * Carried verbatim in intent from `PermissionFixtureMatchesShippedStubTest`: the uuid key on
     * `roles`/`permissions` is not incidental, so assert the claim rather than leaving a reader of a
     * red build to infer it from a type string. Integer-keyed permission tables are the shape this
     * package has never shipped, and the shape the fixture silently had until beam-facade 138.
     */
    public function test_the_shipped_stubs_key_roles_and_permissions_by_uuid(): void
    {
        $this->discoverPairs();

        foreach (['roles', 'permissions'] as $table) {
            $this->assertSame(
                'varchar not-null',
                $this->columns($table, self::PROBE)['id'],
                "`{$table}.id` is not uuid in create_permission_tables.php.stub."
            );

            $this->assertSame(
                'varchar not-null',
                $this->columns($table)['id'],
                "`{$table}.id` is integer-keyed in the fixture — the shape the package has never shipped."
            );
        }
    }

    /**
     * Run every shipped stub's `up()` on the throwaway probe connection, then return the sorted list
     * of tables the probe and the fixture both hold.
     *
     * Order comes from {@see BeamAccountsServiceProvider::gatedEstates()}, which is the package's own
     * declared publish order (package-tools stamps each entry a second apart in listed order, so it
     * is the real migrate order on a host). Stubs on disk that the manifest does not list —
     * `teams/create_visibilities_table` today, which is wired by
     * {@see \Splicewire\Beam\Accounts\Concerns\WiresTeamsMigrations} rather than published through a
     * gate — are appended, sorted, so a new stub is picked up without editing this file.
     *
     * Every stub reaches the database through the DEFAULT connection (the ALTERs deliberately so),
     * which is why the default is repointed rather than the calls being handed a connection.
     */
    private function discoverPairs(): array
    {
        $this->runShippedStubs();

        $probe = $this->tableNames(self::PROBE);
        $fixture = $this->tableNames(null);

        $pairs = array_values(array_intersect($probe, $fixture));
        sort($pairs);

        return $pairs;
    }

    private function runShippedStubs(): void
    {
        if (array_key_exists(self::PROBE, config('database.connections', []))
            && Schema::connection(self::PROBE)->hasTable('users')) {
            return;
        }

        config()->set('database.connections.'.self::PROBE, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // `teams` is forced ON for the probe run because the fixture models a teams host (it carries
        // `team_id` on `roles` and both pivots); with it off the permission stub's
        // `addTeamColumnsIfMissing()` retrofit fires and tries to drop an index the teams branch
        // never created.
        config()->set('permission.teams', true);

        $previous = config('database.default');
        config()->set('database.default', self::PROBE);
        DB::setDefaultConnection(self::PROBE);

        try {
            foreach ($this->stubStems() as $stem) {
                $migration = require dirname(__DIR__)."/database/migrations/{$stem}.php.stub";
                $migration->up();
            }
        } finally {
            config()->set('database.default', $previous);
            DB::setDefaultConnection($previous);
        }
    }

    /** @return list<string> stub stems, in the order a host migrates them */
    private function stubStems(): array
    {
        $declared = [];

        foreach (BeamAccountsServiceProvider::gatedEstates() as $stubs) {
            foreach ($stubs as $stem) {
                $declared[] = $stem;
            }
        }

        $root = dirname(__DIR__).'/database/migrations/';
        $onDisk = [];

        foreach (glob($root.'{,*/}*.php.stub', GLOB_BRACE) as $path) {
            $onDisk[] = substr($path, strlen($root), -strlen('.php.stub'));
        }

        sort($onDisk);

        $missing = array_diff($declared, $onDisk);
        $this->assertSame(
            [],
            array_values($missing),
            'gatedEstates() names a stub that is not on disk: '.implode(', ', $missing)
        );

        $this->assertNotSame([], $onDisk, 'No migration stubs were found on disk.');

        return array_merge($declared, array_values(array_diff($onDisk, $declared)));
    }

    /** @return list<string> */
    private function tableNames(?string $connection): array
    {
        return array_map(
            fn (array $table): string => $table['name'],
            Schema::connection($connection)->getTables()
        );
    }

    /** @return array<string, string> column name => driver type name + nullability */
    private function columns(string $table, ?string $connection = null): array
    {
        $shapes = [];

        foreach (Schema::connection($connection)->getColumns($table) as $column) {
            $shapes[$column['name']] = strtolower($column['type_name']).($column['nullable'] ? ' nullable' : ' not-null');
        }

        return $shapes;
    }
}
