<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The mechanical relation between {@see TestCase::createPersonalAccessTokensSchema()} and the two
 * stubs that produce that table on a host — `create_personal_access_tokens_table.php.stub` and
 * `add_provenance_and_archived_to_personal_access_tokens_table.php.stub`.
 * Same mechanism, same reason, one table over from
 * {@see PermissionFixtureMatchesShippedStubTest} (beam-facade 138).
 *
 * WHY. `baaf6dd` changed the create stub's `morphs('tokenable')` to `uuidMorphs('tokenable')`,
 * because the sibling `shared/create_users_table.php.stub` declares `uuid('id')` and a bigint
 * `tokenable_id` can never hold that key. **Nothing in the suite could see that change in either
 * direction**: the fixture was hand-written in a `beforeEach`, and no test tied it to the stub. The
 * stub could revert and every test would stay green; the fixture could drift and every test would
 * stay green. It had already drifted — `name` was a `string` against the stub's `text` — and no
 * instrument in this package had ever compared the two column lists.
 *
 * So this test does not restate the expected types. It **executes the shipped stubs** onto a
 * second, throwaway in-memory connection — under the real table name, so they run exactly as a host
 * runs them — and diffs the resulting columns, in BOTH directions, against the fixture the rest of
 * the suite runs on. A second connection rather than a prefixed table on the same one: the stub
 * names an index and sqlite index names are database-global, so a same-connection run collides with
 * the fixture's own index before it reaches an assertion.
 *
 * ⚠️ sqlite is dynamically typed, so a type claim here is read off the SCHEMA via the probe
 * connection. A successful insert proves nothing about a column's declared type.
 *
 * SCOPE, stated rather than left to be inferred: this compares COLUMN TYPES and NULLABILITY. It
 * does not compare string lengths (sqlite's introspection does not carry them), indexes, or
 * primary-key composition.
 *
 * NOT IN SCOPE, deliberately: the three other hand-built `personal_access_tokens` fixtures in this
 * suite ({@see KeysModuleEnabledTest}, and `DeterministicTokenTest`'s two). Those model a
 * *different host population* on purpose — a `string` tokenable_id, and a uuid-keyed `id` — because
 * the deterministic minter's whole claim is that it is indifferent to the host's key shape.
 * Converging them to the stub would delete the thing they test.
 */
class PersonalAccessTokenFixtureMatchesShippedStubTest extends TestCase
{
    /**
     * The one column the fixture deliberately does NOT take from the stub.
     *
     * A morph key must MATCH THE HOLDER'S KEY TYPE — matching, not widening. The stub says
     * `uuidMorphs` because the holder it assumes is this package's own
     * `shared/create_users_table.php.stub`, which declares `uuid('id')->primary()`. This harness's
     * holder is {@see TestCase::createUsersSchema()}'s `$table->id()` — the bigint-keyed,
     * pre-existing host `users` that that stub's quiet terminal deliberately leaves alone. So a
     * bigint `tokenable_id` here IS the stub's instruction for this fixture's population, exactly
     * as `model_has_roles.model_id` is in the permission pair.
     *
     * Converging it to uuid would break the bigint-holder population the harness exists to model.
     * It is named here so it is a declared divergence rather than an implicit one.
     */
    private const HOST_DEPENDENT = [
        'tokenable_id',
    ];

    /**
     * The base harness deliberately does not build this table (it is Sanctum's, adopted rather than
     * owned), so this class builds it through the very helper it is pinning — never a local copy,
     * which would put a third hand-written schema in the file whose job is to have none.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createPersonalAccessTokensSchema();
    }

    public function test_every_fixture_column_carries_the_type_the_shipped_stubs_declare(): void
    {
        $this->runShippedStubs();

        $stub = $this->columns('personal_access_tokens', 'stub_probe');
        $fixture = $this->columns('personal_access_tokens');

        $this->assertNotSame([], $stub, 'The shipped stubs created no `personal_access_tokens`.');

        $compared = 0;

        foreach ($stub as $column => $shape) {
            if (in_array($column, self::HOST_DEPENDENT, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $column,
                $fixture,
                "`personal_access_tokens.{$column}` is shipped by the package's stubs and missing from the test fixture."
            );

            $this->assertSame(
                $shape,
                $fixture[$column],
                "`personal_access_tokens.{$column}` is `{$shape}` in the package's shipped stubs and `{$fixture[$column]}` in the test fixture."
            );

            $compared++;
        }

        // The other direction, which the permission pair does not check and this one can: the two
        // stubs are the ONLY thing that builds this table on a host, so a column the fixture has and
        // they do not is a column no host will ever have.
        foreach (array_keys($fixture) as $column) {
            $this->assertArrayHasKey(
                $column,
                $stub,
                "`personal_access_tokens.{$column}` is in the test fixture and shipped by no stub — no host has this column."
            );
        }

        // A guard on the guard: if the stubs ever stop creating this table, the loop above becomes
        // vacuous and the test passes over nothing — the exact failure mode this file exists to
        // close. 11 is what the two stubs declare today (12 columns, less the one exemption); this
        // floor catches a collapse, not a deliberate addition.
        $this->assertGreaterThanOrEqual(11, $compared, 'The stub/fixture diff compared almost nothing.');
    }

    /**
     * The morph is the reason `baaf6dd` exists, so assert it directly rather than leaving a reader
     * of a red build to infer the claim from a type string. This is the assertion that goes red if
     * the stub is reverted to Sanctum's stock `morphs('tokenable')`.
     */
    public function test_the_shipped_stub_declares_a_uuid_morph(): void
    {
        $this->runShippedStubs();

        $stub = $this->columns('personal_access_tokens', 'stub_probe');

        $this->assertSame(
            'varchar not-null',
            $stub['tokenable_id'],
            '`tokenable_id` is not a uuid in create_personal_access_tokens_table.php.stub — a bigint morph '
            .'cannot hold the key shared/create_users_table.php.stub writes.'
        );

        // The fixture's counterpart is bigint, and that is correct FOR ITS HOLDER. Pin the
        // divergence rather than only exempting it, so silently converging either side is also red.
        $this->assertSame(
            'integer not-null',
            $this->columns('personal_access_tokens')['tokenable_id'],
            'The fixture`s `tokenable_id` no longer matches its own bigint-keyed `users` holder.'
        );
    }

    /**
     * Run both stubs' `up()` on the throwaway `stub_probe` connection, under the real table name and
     * in the order `BeamAccountsServiceProvider::configurePackage()` lists them — the create first,
     * then the ALTER that adds `provenance`/`archived_at`. Both reach the database through the
     * DEFAULT connection (the ALTER deliberately so), which is why the default is repointed rather
     * than the calls being given a connection.
     */
    private function runShippedStubs(): void
    {
        config()->set('database.connections.stub_probe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $previous = config('database.default');
        config()->set('database.default', 'stub_probe');
        DB::setDefaultConnection('stub_probe');

        try {
            foreach (['create_personal_access_tokens_table', 'add_provenance_and_archived_to_personal_access_tokens_table'] as $stem) {
                $migration = require dirname(__DIR__)."/database/migrations/{$stem}.php.stub";
                $migration->up();
            }
        } finally {
            config()->set('database.default', $previous);
            DB::setDefaultConnection($previous);
        }
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
