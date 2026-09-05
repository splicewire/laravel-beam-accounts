<?php

namespace Splicewire\Beam\Accounts\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Authorization\RolePermissions;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * Backfill: re-sync the permission rows onto every team-scoped spatie role that already exists.
 *
 * {@see \Splicewire\Beam\Accounts\Teams\TeamProvisioner::syncSpatieRole()} attaches a role's tokens
 * at the moment the role row is created, which covers every team from here on. It does NOT cover
 * teams provisioned BEFORE that existed — and that is every team at every beam host, since no
 * permission row was ever written anywhere in the family. Measured at `~/Herd/beam` right after the
 * provisioner change landed: the two demo teams the seeder touched held 30 and 30 tokens, while the
 * two personal teams seeded earlier in the same database still held 0.
 *
 * Also the convergence path when a host RETIERS `beam.accounts.roles.abilities`, or when a package
 * that adds a `#[UseCascadePolicy]` model is installed into a host whose teams already exist: the
 * token set is derived per run, so `splicewire:beam:seed` brings every role back in line.
 *
 * ⚠️ Ungated, unlike its sibling {@see DemoTeamSeeder}. This writes no subjects and fabricates no
 * data — it re-derives authorization for rows the host already has, so a production seed run wants
 * it. It skips itself when the `roles` table is absent (a host that declined the permission estate).
 */
class RolePermissionsSeeder extends Seeder
{
    public function __construct(protected RolePermissions $permissions) {}

    public function run(): void
    {
        $roleModel = config('permission.models.role');

        if ($roleModel === null || ! Schema::hasTable((new $roleModel)->getTable())) {
            $this->command?->warn('beam-accounts: role permissions skipped (no roles table at this host).');

            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $teamKey = config('permission.column_names.team_foreign_key', 'team_id');
        $synced = 0;

        foreach ($roleModel::query()->cursor() as $role) {
            // A role row whose name is outside the Role vocabulary is the host's own (a stale value,
            // or a role a host defines itself). Leaving it alone is the point: this seeder must not
            // silently strip a host-authored role's permissions down to an empty derived set.
            if (Role::tryFrom($role->name) === null) {
                continue;
            }

            // Roles are team-scoped; the sync writes `role_has_permissions` through the registrar's
            // ambient team state, so it is set per row rather than once.
            $registrar->setPermissionsTeamId($role->getAttribute($teamKey));

            $this->permissions->syncTo($role, $role->name);
            $synced++;
        }

        $registrar->setPermissionsTeamId($previous);
        $registrar->forgetCachedPermissions();

        $this->command?->info("beam-accounts: role permissions synced onto {$synced} team role(s) across "
            .count($this->permissions->policedModels()).' policed model(s).');
    }
}
