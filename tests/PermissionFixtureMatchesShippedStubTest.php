<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The mechanical relation between {@see TestCase::createSpatieSchema()} and
 * `database/migrations/shared/create_permission_tables.php.stub` (beam-facade 138).
 *
 * Until 138 there were two hand-written permission schemas in this package — one shipped, one in the
 * test base — with **nothing comparing them**, and they had silently disagreed on the single most
 * load-bearing detail in the file: the primary key type. Every one of the package's tests ran on
 * integer-keyed `roles`, so `TeamProvisioner::syncSpatieRole()` never once met the NOT NULL that
 * `Models\Role` exists to satisfy. Converging the fixture by hand fixes that instance and leaves the
 * mechanism — two hand-written schemas — exactly as it was.
 *
 * So this test does not restate the expected types. It **executes the shipped stub** onto a second,
 * throwaway in-memory connection — under the real table names, so the stub runs exactly as a host
 * runs it — and diffs the resulting column types, column by column, against the fixture the rest of
 * the suite runs on. Change either side alone and this goes red naming the column. (A second
 * connection rather than a `stub_`-prefixed set on the same one: the stub names its indexes
 * explicitly and sqlite index names are database-global, so a same-connection run collides with the
 * fixture's own index names before it reaches a single assertion.)
 *
 * SCOPE, stated rather than left to be inferred: this compares COLUMN TYPES, which is the drift
 * class 138 is about. It does not compare indexes or primary-key composition — the stub's pivot key
 * shape is `$teams`-conditional and the fixture pins one branch of it, which is a fixture choice
 * rather than drift.
 */
class PermissionFixtureMatchesShippedStubTest extends TestCase
{
    /**
     * The one column the fixture deliberately does NOT take from the stub, and the stub's own
     * docblock is the authority for it: `model_morph_key` "MUST MATCH THE HOLDER'S KEY TYPE ...
     * matching, not widening". `create_users_table.php.stub` is a quiet terminal that leaves a
     * pre-existing bigint-keyed host `users` alone, {@see TestCase::createUsersSchema()} builds
     * exactly that holder, so bigint here IS the stub's instruction for this population.
     *
     * Keyed `<table>.<column>` so this can never silently exempt a second table's column.
     */
    private const HOST_DEPENDENT = [
        'model_has_roles.model_id',
        'model_has_permissions.model_id',
    ];

    /** The five tables the stub owns. */
    private const TABLES = [
        'permissions',
        'roles',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
    ];

    public function test_every_fixture_column_carries_the_type_the_shipped_stub_declares(): void
    {
        $this->runShippedStub();

        $compared = 0;

        foreach (self::TABLES as $table) {
            $stub = $this->columnTypes($table, 'stub_probe');
            $fixture = $this->columnTypes($table);

            $this->assertNotSame([], $stub, "The shipped stub created no `{$table}`.");

            foreach ($stub as $column => $type) {
                if (in_array("{$table}.{$column}", self::HOST_DEPENDENT, true)) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $column,
                    $fixture,
                    "`{$table}.{$column}` is shipped by create_permission_tables.php.stub and missing from the test fixture."
                );

                $this->assertSame(
                    $type,
                    $fixture[$column],
                    "`{$table}.{$column}` is `{$type}` in create_permission_tables.php.stub and `{$fixture[$column]}` in the test fixture."
                );

                $compared++;
            }
        }

        // A guard on the guard: if the stub ever stops creating these tables, every loop above
        // becomes vacuous and the test passes over nothing — the exact failure mode 138 exists to
        // close. 19 is what the stub declares today (5 + 6 + 3 + 3 + 2, less the two exemptions);
        // this floor catches a collapse, not a deliberate addition.
        $this->assertGreaterThanOrEqual(19, $compared, 'The stub/fixture diff compared almost nothing.');
    }

    /**
     * The uuid key is not incidental: assert it directly, so a reader of a red build sees the claim
     * and not only a type string.
     */
    public function test_the_shipped_stub_keys_roles_and_permissions_by_uuid(): void
    {
        $this->runShippedStub();

        foreach (['roles', 'permissions'] as $table) {
            $this->assertSame(
                $this->columnTypes($table, 'stub_probe')['id'],
                $this->columnTypes($table)['id'],
                "`{$table}.id` diverges between the shipped stub and the test fixture."
            );

            $this->assertNotSame(
                'integer',
                $this->columnTypes($table)['id'],
                "`{$table}.id` is integer-keyed — the shape the package has never shipped."
            );
        }
    }

    /**
     * Run `create_permission_tables.php.stub`'s `up()` on the throwaway `stub_probe` connection,
     * under the real table names.
     *
     * `teams` is forced ON for the probe run because the fixture models a teams host (it carries
     * `team_id` on `roles` and both pivots); with it off the stub's `addTeamColumnsIfMissing()`
     * retrofit fires and tries to drop an index the teams branch never created.
     */
    private function runShippedStub(): void
    {
        config()->set('database.connections.stub_probe', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('permission.teams', true);

        $previous = config('database.default');
        config()->set('database.default', 'stub_probe');
        DB::setDefaultConnection('stub_probe');

        try {
            $migration = require dirname(__DIR__).'/database/migrations/shared/create_permission_tables.php.stub';
            $migration->up();
        } finally {
            config()->set('database.default', $previous);
            DB::setDefaultConnection($previous);
        }
    }

    /** @return array<string, string> column name => driver type name */
    private function columnTypes(string $table, ?string $connection = null): array
    {
        $types = [];

        foreach (Schema::connection($connection)->getColumns($table) as $column) {
            $types[$column['name']] = strtolower($column['type_name']);
        }

        return $types;
    }
}
