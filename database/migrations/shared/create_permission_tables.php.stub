<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Squashed pre-prod (no deployed data to preserve migration history for) from the original central
 * create plus its near-identical tenant twin (confirmed schema-identical — the two differed only in
 * comments/whitespace plus the central copy's extra `addTeamColumnsIfMissing` defensive backfill
 * branch, itself schema-neutral) into ONE canonical create.
 *
 * SHARED (central + every tenant): Spatie permission tables on whichever connection this runs
 * against, so both the central User model (HasRoles, e.g. the Root role) and any tenant model can
 * hold roles/permissions.
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

        if (Schema::hasTable($tableNames['permissions'])) {
            // Tables exist (e.g. from schema dump) but may lack team columns.
            $this->addTeamColumnsIfMissing($tableNames, $columnNames);

            return;
        }

        Schema::create($tableNames['permissions'], function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
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
        });

        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
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
        });

        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
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
        });

        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
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
        });

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
