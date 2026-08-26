<?php

namespace Splicewire\Beam\Accounts\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Accounts\Doctor\PermissionModelPairingAudit;
use Splicewire\Beam\Accounts\Tests\TestCase;

/**
 * The pairing audit's four states, driven over synthetic migration trees rather than the package's own
 * fixture — the point of the check is what a HOST's disk says, and the four states are deliberately
 * not all reachable from one host at once.
 *
 * The `int`/`int` case is the one worth reading twice: it is a **working** host and it is reported
 * anyway, because the repair AGENTS.md prescribes for it (republish the package stub, basename-matched)
 * is the act that converts it into the fatal case.
 */
class PermissionModelPairingAuditTest extends TestCase
{
    protected string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/beam-accounts-pairing-'.bin2hex(random_bytes(6));
        mkdir($this->scratch.'/shared', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/shared/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->scratch.'/shared');
        @rmdir($this->scratch);

        parent::tearDown();
    }

    public function test_a_uuid_publish_bound_to_an_integer_model_fails_and_names_the_shipped_pair(): void
    {
        $this->writeMigration('uuid');

        config([
            'permission.models.role' => 'Spatie\Permission\Models\Role',
            'permission.models.permission' => 'Spatie\Permission\Models\Permission',
        ]);

        $findings = (new PermissionModelPairingAudit($this->scratch))->run();

        $this->assertCount(2, $findings, 'both roles and permissions are unpaired');

        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Fail, $finding->status);
            $this->assertStringContainsString('500 on signup', $finding->detail);
        }

        $this->assertStringContainsString(PermissionModelPairingAudit::SHIPPED_ROLE, $findings[0]->detail);
        $this->assertStringContainsString(PermissionModelPairingAudit::SHIPPED_PERMISSION, $findings[1]->detail);
    }

    public function test_a_uuid_publish_bound_to_the_shipped_pair_passes(): void
    {
        $this->writeMigration('uuid');

        config([
            'permission.models.role' => PermissionModelPairingAudit::SHIPPED_ROLE,
            'permission.models.permission' => PermissionModelPairingAudit::SHIPPED_PERMISSION,
        ]);

        $findings = (new PermissionModelPairingAudit($this->scratch))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_a_working_integer_host_is_still_reported_because_the_prescribed_repair_breaks_it(): void
    {
        $this->writeMigration('bigIncrements');

        config([
            'permission.models.role' => 'Spatie\Permission\Models\Role',
            'permission.models.permission' => 'Spatie\Permission\Models\Permission',
        ]);

        $findings = (new PermissionModelPairingAudit($this->scratch))->run();

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('TWO-FILE ATOMIC CHANGE', $findings[0]->detail);
        $this->assertStringContainsString('BASENAME', $findings[0]->detail);
    }

    public function test_a_declared_integer_pin_silences_the_working_integer_host(): void
    {
        $this->writeMigration('bigIncrements');

        config([
            'permission.models.role' => 'Spatie\Permission\Models\Role',
            'permission.models.permission' => 'Spatie\Permission\Models\Permission',
        ]);

        $findings = (new PermissionModelPairingAudit($this->scratch, 'int'))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('pinned to int', $findings[0]->detail);
    }

    public function test_a_pin_that_contradicts_the_published_schema_is_itself_a_finding(): void
    {
        $this->writeMigration('uuid');

        $findings = (new PermissionModelPairingAudit($this->scratch, 'int'))->run();

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('stale one is worse than none', $findings[0]->detail);
    }

    /**
     * The estate has TWO creators live at once — Spatie's `Schema::create` at `~/Herd/numero` and the
     * convergent guard beam-accounts' own stub now uses at `~/Herd/tower`. Measured: a first cut of this
     * audit knew only the first and reported "could not read this statically" at precisely the hosts
     * that are wired correctly.
     */
    public function test_the_convergent_creator_is_read_as_well_as_schema_create(): void
    {
        $this->writeConvergentMigration();

        config([
            'permission.models.role' => 'Spatie\Permission\Models\Role',
            'permission.models.permission' => 'Spatie\Permission\Models\Permission',
        ]);

        $findings = (new PermissionModelPairingAudit($this->scratch))->run();

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('uuid primary key', $findings[0]->detail);
    }

    public function test_no_published_migration_reports_the_blind_spot_rather_than_a_clean_bill(): void
    {
        $findings = (new PermissionModelPairingAudit($this->scratch))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('blind spot', $findings[0]->detail);
    }

    /**
     * A minimal `create_permission_tables` in the two shapes the estate actually has on disk, addressing
     * both tables through `$tableNames[...]` exactly as Spatie's stub, beam-accounts' stub and every host
     * publish of either do.
     */
    protected function writeMigration(string $key): void
    {
        $declaration = $key === 'uuid' ? "\$table->uuid('id')->primary();" : "\$table->bigIncrements('id');";

        file_put_contents($this->scratch.'/shared/2026_01_01_000000_create_permission_tables.php', <<<PHP
        <?php

        return new class
        {
            public function up(): void
            {
                Schema::create(\$tableNames['permissions'], static function (\$table) {
                    {$declaration}
                    \$table->string('name');
                });

                Schema::create(\$tableNames['roles'], static function (\$table) {
                    {$declaration}
                    \$table->string('name');
                });

                Schema::create(\$tableNames['model_has_roles'], static function (\$table) {
                    \$table->uuid('model_id');
                });
            }
        };
        PHP);
    }

    /**
     * beam-accounts' own current shape, trimmed: the convergent creator, plus the `Schema::table` /
     * `Schema::hasColumn` teams backfill that follows every create in the real stub and would put a
     * false block boundary mid-block if the creator set were matched by subscript alone.
     */
    protected function writeConvergentMigration(): void
    {
        file_put_contents($this->scratch.'/shared/2026_01_01_000000_create_permission_tables.php', <<<'PHP'
        <?php

        return new class
        {
            public function up(): void
            {
                ConvergentTable::named($tableNames['permissions'])
                    ->create(static function ($table) {
                        $table->uuid('id')->primary();
                        $table->string('name');
                    });

                ConvergentTable::named($tableNames['roles'])
                    ->create(static function ($table) {
                        $table->uuid('id')->primary();
                        $table->string('name');
                    });

                ConvergentTable::named($tableNames['model_has_roles'])
                    ->create(static function ($table) {
                        $table->uuid('model_id');
                    });

                if (! Schema::hasColumn($tableNames['roles'], 'team_id')) {
                    Schema::table($tableNames['roles'], function ($table) {
                        $table->unsignedBigInteger('team_id')->nullable();
                    });
                }
            }
        };
        PHP);
    }
}
