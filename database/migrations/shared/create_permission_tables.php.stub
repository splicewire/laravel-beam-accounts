<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * Squashed pre-prod (no deployed data to preserve migration history for) from the original central
 * create plus its near-identical tenant twin (confirmed schema-identical — the two differed only in
 * comments/whitespace plus the central copy's extra `addTeamColumnsIfMissing` defensive backfill
 * branch, itself schema-neutral) into ONE canonical create.
 *
 * SHARED (central + every tenant): Spatie permission tables on whichever connection this runs
 * against, so both the central User model (HasRoles, e.g. the Root role) and any tenant model can
 * hold roles/permissions.
 *
 * `roles`/`permissions` own PKs are uuid (the cross-host morph-key convention — see
 * `App\Models\Role`/`Permission` in a consuming host). `model_morph_key` (`model_id` on
 * `model_has_roles`/`model_has_permissions`) stores the PK of whatever model HOLDS the role, and it
 * is **uuid**, matching this package's uuid-native `users`.
 *
 * IT MUST MATCH THE HOLDER'S KEY TYPE — it cannot be a permissive `string`. This column is not a
 * loose morph reference that only ever meets a BOUND value; Eloquent JOINS it against the holder's
 * primary key. `HasRoles::roles()` is a `morphToMany`, so spatie's `role()`/`permission()` scopes
 * and any `whereHas('roles')` emit a correlated subquery comparing the two COLUMNS directly:
 *
 *     select * from "users" where exists (
 *         select * from "roles"
 *         inner join "model_has_roles" on "roles"."id" = "model_has_roles"."role_id"
 *         where "users"."id" = "model_has_roles"."model_id" ...)
 *
 * Postgres has no implicit uuid↔varchar cast, so a `string` column makes every one of those queries
 * die with `operator does not exist: uuid = character varying`. It is not a narrow failure: it takes
 * out `User::role('Root')`, which is how the root user is resolved throughout the estate's tests.
 *
 * That is the distinction against the `AccessGrant.grantable_id`/`grantee_id` idiom this column was
 * briefly changed to mirror: those are read with a bound value (`where grantable_id = ?`), which
 * Postgres coerces happily, so `string` costs them nothing. A pivot key that Eloquent joins on has
 * no such freedom. A host that keeps its own bigint-keyed `users` (see `config/beam/accounts.php`'s
 * `register_auth_migrations`) therefore needs this column to be bigint — matching, not widening.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        if (empty($tableNames)) {
            throw new Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }
        if ($teams && empty($columnNames['team_foreign_key'] ?? null)) {
            throw new Exception('Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        // Each create below carries its OWN convergent guard. The single
        // `if (Schema::hasTable($tableNames['permissions'])) return;` this replaces spoke for all five
        // tables at once, so a host that already owned `roles` — 13's Lunar `roles.id` wall, bigint
        // where beam is uuid — kept an incompatible one and the guard reported success.
        ConvergentTable::named($tableNames['permissions'])
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();

                $table->unique(['name', 'guard_name']);
            })
            ->assert();

        ConvergentTable::named($tableNames['roles'])
            ->define(function (Blueprint $table) use ($teams, $columnNames) {
                $table->uuid('id')->primary();
                if ($teams || config('permission.testing')) {
                    $table->string($columnNames['team_foreign_key'])->nullable();
                    $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
                }
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                if ($teams || config('permission.testing')) {
                    $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
                } else {
                    $table->unique(['name', 'guard_name']);
                }
            })
            ->assert();

        ConvergentTable::named($tableNames['model_has_permissions'])
            ->define(function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
                $table->uuid($pivotPermission);

                $table->string('model_type');
                $table->uuid($columnNames['model_morph_key']);
                $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->onDelete('cascade');
                if ($teams) {
                    // Nullable because platform-level permission assignments (e.g. Root)
                    // have no team context. Use a unique index instead of a composite
                    // primary key so PostgreSQL allows NULLs.
                    $table->string($columnNames['team_foreign_key'])->nullable();
                    $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

                    $table->unique([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_permissions_permission_model_type_primary');
                } else {
                    $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_permissions_permission_model_type_primary');
                }
            })
            ->assert();

        ConvergentTable::named($tableNames['model_has_roles'])
            ->define(function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
                $table->uuid($pivotRole);

                $table->string('model_type');
                $table->uuid($columnNames['model_morph_key']);
                $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->onDelete('cascade');
                if ($teams) {
                    // Nullable because platform-level role assignments (e.g. Root)
                    // have no team context. Use a unique index instead of a composite
                    // primary key so PostgreSQL allows NULLs.
                    $table->string($columnNames['team_foreign_key'])->nullable();
                    $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

                    $table->unique([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_roles_role_model_type_primary');
                } else {
                    $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                        'model_has_roles_role_model_type_primary');
                }
            })
            ->assert();

        ConvergentTable::named($tableNames['role_has_permissions'])
            ->define(function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
                $table->uuid($pivotPermission);
                $table->uuid($pivotRole);

                $table->foreign($pivotPermission)
                    ->references('id')
                    ->on($tableNames['permissions'])
                    ->onDelete('cascade');

                $table->foreign($pivotRole)
                    ->references('id')
                    ->on($tableNames['roles'])
                    ->onDelete('cascade');

                $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
            })
            ->assert();

        // Tables may pre-exist (e.g. from a schema dump) without the team columns; convergence adds a
        // declared column, and this covers the ones the Blueprint above only declares conditionally.
        $this->addTeamColumnsIfMissing($tableNames, $columnNames);

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Add team columns to existing permission tables that were loaded from a
     * schema dump created before teams mode was enabled.
     */
    private function addTeamColumnsIfMissing(array $tableNames, array $columnNames): void
    {
        $teamFK = $columnNames['team_foreign_key'];

        if (! Schema::hasColumn($tableNames['roles'], $teamFK)) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamFK) {
                $table->string($teamFK)->nullable()->after('id');
                $table->index($teamFK, 'roles_team_foreign_key_index');
            });

            // Replace unique constraint with team-aware version
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamFK) {
                $table->dropUnique('roles_name_guard_name_unique');
                $table->unique([$teamFK, 'name', 'guard_name']);
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_roles'], $teamFK)) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamFK) {
                $table->string($teamFK)->nullable();
                $table->index($teamFK, 'model_has_roles_team_foreign_key_index');
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_permissions'], $teamFK)) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamFK) {
                $table->string($teamFK)->nullable();
                $table->index($teamFK, 'model_has_permissions_team_foreign_key_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        if (empty($tableNames)) {
            throw new Exception('Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
        }

        Schema::drop($tableNames['role_has_permissions']);
        Schema::drop($tableNames['model_has_roles']);
        Schema::drop($tableNames['model_has_permissions']);
        Schema::drop($tableNames['roles']);
        Schema::drop($tableNames['permissions']);
    }
};
