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
     * **EMPTY, and that is the finding.** This list held nine entries until 2026-08-30. Every one of
     * them descended from a single claim in this docblock — that the fixture models a pre-existing
     * bigint-keyed host `users`, "because it is the population the estate actually has:
     * `~/Herd/audiostud`, `~/Herd/fable` and `~/Herd/numero`."
     *
     * That population does not exist. All three of those hosts publish
     * `$table->uuid('id')->primary()` in their own `0001_01_01_000000_create_users_table.php`, and so
     * do the other six `~/Herd` roots that install this package (`beam`, `satellite`, `schemastud`,
     * `splicewire`, plus `splicewire-app` and `tower`, whose `tenant_users` pivots take a
     * `uuid('user_id')`). **Zero hosts in the estate have a bigint `users.id`.** What those three ARE
     * integer-keyed on is `roles.id`/`permissions.id` — a different column, pinned in their own
     * `config/beam/accounts.php`, which says in-file that the pin "covers `roles.id`/`permissions.id`
     * ONLY". The docblock had read one host fact and attributed it to another table.
     *
     * The stubs were then run onto TWO throwaway connections — one greenfield, one seeded first with a
     * hand-built bigint `users` holder (a `laravel new` derivative) — and the resulting schemas diffed.
     * **They are identical on every column except `users.id` itself.** `create_permission_tables`
     * writes `$table->uuid($columnNames['model_morph_key'])` unconditionally at both sites;
     * `create_personal_access_tokens` writes `uuidMorphs`; `create_teams`, `create_memberships` and
     * `create_invitations` all write uuid at their `users` foreign keys. Nothing in the shipped code
     * reads the holder's key type — `config/beam/accounts.php` says so outright of `key_type`: it
     * "is not a switch — nothing reads it at runtime."
     *
     * So the six morph/FK entries were not "the stub's instruction for this population". They were a
     * repair the shipped code does not perform, encoded as fixture, for a population of zero — and two
     * of them (`model_has_roles.model_id`, `model_has_permissions.model_id`) pinned the exact
     * pre-beam-facade-142 defect shape that ticket closed on 2026-08-26. Converging them turned the
     * fixture from the INVERSE of every real host into the shape every real host has, at a cost of one
     * `HasUuids` on the test `User` and a `password` on 13 `User::create()` calls (the stub and every
     * host declare `password` NOT NULL, so a passwordless insert was itself a shape no host has).
     *
     * The witness that a divergence is real, if one is ever added back: it must name a host on disk
     * whose schema has that shape, checkable with `KeyTypeConformanceAudit` /
     * `SchemaKeyIndex::keyTypeOfClass()`, which read live host schemas. A `why` that only argues from
     * a stub docblock is how this list reached nine — the permission stub's own prose says a bigint
     * host "needs this column to be bigint", and its code has never done that.
     *
     * @var array<string, array{stub: string, fixture: string, why: string}>
     */
    private const DECLARED_DIVERGENCES = [];

    /**
     * A floor on how many columns the diff actually compared, so a collapse of the mechanism cannot
     * report success by not running. 90 is what the stubs declare today across the 13 pairs, with no
     * declared divergences left to subtract; this catches a collapse, not a deliberate addition.
     */
    private const COMPARED_FLOOR = 90;

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

        $this->assertCount(
            0,
            self::DECLARED_DIVERGENCES,
            'The declared-divergence list is EMPTY on purpose — the fixture is the shipped shape. Adding '
            .'an entry needs a host on disk that actually has it; see the constant\'s docblock.'
        );
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
     * `beam_invitations.team_id` holds a {@see \Splicewire\Beam\Accounts\Contracts\TeamContract} key, and that contract declares the key
     * `int|string` — *"It says nothing about the backing table, key type, or provisioning: those are
     * the host's private seam."* So the column may not be a bigint, in the stub or in the fixture.
     *
     * This is the same class of claim as the uuid one above and it is asserted for the same reason:
     * a bigint here does not merely inconvenience a string-keyed host, it makes the invitation
     * surface structurally unable to name that host's team at all — which is what forked
     * `splicewire/laravel-beam-tenancy`'s `tenant_invitations` off in the first place.
     *
     * ⚠️ sqlite is dynamically typed, which is precisely why this reads the DECLARED type rather than
     * inserting a string and calling the insert a proof. `TeamResolverSeamTest` has been writing
     * `team_id => 'host-team-1'` into this column and passing since it was written; on Postgres the
     * same line is an error.
     */
    public function test_the_shipped_stubs_do_not_pin_the_invitation_team_key_to_a_bigint(): void
    {
        $this->discoverPairs();

        foreach ([self::PROBE => 'the package\'s shipped stub', null => 'the test fixture'] as $connection => $where) {
            $shape = $this->columns('beam_invitations', $connection === '' ? null : $connection)['team_id'] ?? null;

            $this->assertSame(
                'varchar not-null',
                $shape,
                "`beam_invitations.team_id` is `{$shape}` in {$where}. It carries a TeamContract key, which "
                .'that contract declares `int|string`, so it cannot be an integer column.'
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
