<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Splicewire\Beam\Accounts\Models\Permission;
use Splicewire\Beam\Accounts\Models\Role;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;

/**
 * The uuid-keyed `Role`/`Permission` pair, and the binding this package deliberately does not make
 * (beam-facade tickets 79, 98).
 *
 * The base {@see TestCase} builds `roles`/`permissions` with `$table->id()` — INTEGER keys, which
 * contradict this package's own `create_permission_tables` stub and are why the existing suite has
 * never met the constraint the pair exists for. Rather than flip that helper (it would re-shape every
 * test in the package for one case's benefit), this file drops those two tables and rebuilds them the
 * way the shipped stub does, which is the only fixture on which the assertions below mean anything.
 */
class UuidKeyedPermissionModelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildPermissionTablesUuidKeyed();
    }

    /**
     * The acceptance case, and it is {@see TeamProvisioner::syncSpatieRole()}'s exact call:
     * `app(config('permission.models.role'))::findOrCreate($name, $guard)` against a uuid-keyed
     * `roles` table. Stock Spatie generates no key, so the insert dies on NOT NULL; `HasUuids` is
     * the whole of the repair.
     */
    public function test_role_find_or_create_lands_a_uuid_on_a_uuid_keyed_roles_table(): void
    {
        $role = Role::findOrCreate('Admin', 'web');

        $this->assertTrue(Str::isUuid($role->getKey()), 'Role::findOrCreate() must generate a uuid key.');
        $this->assertDatabaseHas('roles', ['id' => $role->getKey(), 'name' => 'Admin']);

        // findOrCreate is find-OR-create: a second call must not mint a second uuid for one name.
        $this->assertSame($role->getKey(), Role::findOrCreate('Admin', 'web')->getKey());
    }

    public function test_permission_find_or_create_lands_a_uuid_too(): void
    {
        $permission = Permission::findOrCreate('teams.manage', 'web');

        $this->assertTrue(Str::isUuid($permission->getKey()));
        $this->assertDatabaseHas('permissions', ['id' => $permission->getKey()]);
    }

    /**
     * The reason the pair exists at all. Without this, "we added `HasUuids`" is an unfalsifiable
     * claim — the assertion above would pass just as happily on a table with an auto-increment key.
     */
    public function test_stock_spatie_cannot_create_a_role_on_the_same_table(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SpatieRole::findOrCreate('Admin', 'web');
    }

    public function test_stock_spatie_cannot_create_a_permission_on_the_same_table(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        SpatiePermission::findOrCreate('teams.manage', 'web');
    }

    /**
     * **The load-bearing half of ticket 98.** Shipping the pair is safe; DEFAULTING it is not, and
     * this is the guard that keeps a future "surely we should just bind it" edit from landing.
     *
     * Measured across the estate on 2026-08-26: `~/Herd/audiostud`, `~/Herd/numero` and
     * `~/Herd/fable` install beam-accounts and run stock integer-keyed Spatie over an older
     * `bigIncrements('id')` publish of the permission tables. `fable` has no `config/permission.php`
     * at all, so it is exactly the host a package-level default would reach — and reaching it would
     * push uuid strings at a bigint primary key on a live database.
     *
     * The assertion is against SPATIE's own published defaults, which is what an unconfigured host
     * resolves. If this ever goes red, the question is not "update the expectation."
     */
    public function test_the_provider_leaves_permission_models_untouched(): void
    {
        $this->assertSame(SpatieRole::class, config('permission.models.role'));
        $this->assertSame(SpatiePermission::class, config('permission.models.permission'));

        $this->assertNotSame(Role::class, config('permission.models.role'));
        $this->assertNotSame(Permission::class, config('permission.models.permission'));
    }

    /**
     * The pair is a key-generation fix and nothing else — it must not quietly acquire a connection
     * pin. `splicewire/tower`'s pair does pin `central`, which is the deferred ADR
     * `CentralPinJustificationAudit` names; folding it in here would silently resolve it.
     */
    public function test_the_pair_pins_no_connection(): void
    {
        $this->assertNull((new Role)->getConnectionName());
        $this->assertNull((new Permission)->getConnectionName());
    }

    /**
     * Rebuild `roles`/`permissions` the way `database/migrations/shared/create_permission_tables.php.stub`
     * does — uuid primary keys — leaving the pivots alone, since nothing here joins them.
     */
    private function rebuildPermissionTablesUuidKeyed(): void
    {
        Schema::drop('roles');
        Schema::drop('permissions');

        Schema::create('permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->nullable();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['team_id', 'name', 'guard_name']);
        });
    }
}
