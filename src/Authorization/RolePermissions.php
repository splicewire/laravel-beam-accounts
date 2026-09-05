<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * The permission rows a team's spatie roles hold — the half of the authorization model that was
 * never provisioned.
 *
 * ## What was broken
 *
 * `rushing/laravel-permission-cascade`'s {@see BaseModelPolicy::viewAny()} answers
 * `$user->can('<morph-alias>.view') || $user->can('<morph-alias>.own.view')`. A model carrying
 * `#[UseCascadePolicy]` therefore denies EVERY ability to EVERY principal until some seeder has
 * written those permission rows and attached them to a role the principal holds. Nothing in the
 * beam family did: measured at `~/Herd/beam` on 2026-09-05, `permissions` held **0 rows** against
 * 6 team-scoped `roles` rows, so `demo-owner@example.test` — the OWNER of the demo team — was
 * denied `viewAny` on Entries, Schemas, Files, Sitemap and Hooks, and the tenant nav rendered the
 * single row (Ops → Git Repos) whose model has no policy at all. Secure-by-omission, faithfully
 * reported. The flagship (`~/Herd/splicewire-app`) hand-authored a 200-line
 * `Database\Seeders\PermissionsSeeder` naming every model; every other host got nothing.
 *
 * ## The model set is DERIVED, never listed
 *
 * The universe of tokens is exactly the models bound to a cascade policy, and the Gate already
 * holds that list — `CascadePolicyRegistrar::register()` calls `Gate::policy()` from each owning
 * package's provider, so {@see Gate::policies()} is the estate-wide census of policed models at
 * whatever host happens to be booted. Reading it means:
 *
 * - beam-accounts never names a model from a package above it (`BeamUxEntry` lives in beam-ux,
 *   which depends DOWN on this package — the same direction constraint `RealmReachGrant` hit), and
 * - a package that adds a `#[UseCascadePolicy]` model gets working roles at every host with no
 *   registration call and no seeder edit, which a hand-maintained manifest would not give.
 *
 * The cascade registrar binds under a synthetic container key (`permission-cascade-policy:<model>`)
 * rather than a class-string, so both spellings are accepted below: the synthetic key, and any
 * hand-written {@see BaseModelPolicy} subclass, which resolves tokens the identical way.
 *
 * ## Tiering
 *
 * Abilities per role come from `beam.accounts.roles.abilities`, keyed by {@see Role} value, so a
 * host retiers without touching this class. The shipped default reads the roles as their names
 * promise: Owner may destroy and restore, Admin may author, Member may read. It is deliberately
 * NOT uniform — four demo subjects that all hold the same tokens demonstrate nothing.
 *
 * ## Where this fires
 *
 * {@see \Splicewire\Beam\Accounts\Teams\TeamProvisioner::syncSpatieRole()}, which is the one place
 * a team-scoped spatie role row is created. Every team gets its permissions at the moment its role
 * exists — a demo seed, a real registration, an invitation accepted — instead of only where a host
 * remembered to run a seeder. Idempotent: `findOrCreate` + `syncPermissions`.
 *
 * ⚠️ Permissions are GLOBAL; roles are TEAM-SCOPED (measured: `permissions` has no `team_id`
 * column, `roles` does). So the permission rows are written once and every team's own role row
 * links to the same ones. A caller must have set the registrar's team id before calling
 * {@see syncTo()} — the role row it hands over is already team-bound, but spatie resolves the
 * `role_has_permissions` writes through the ambient registrar state.
 */
class RolePermissions
{
    /**
     * The default ability tiers, by {@see Role} value. Overridden wholesale by
     * `beam.accounts.roles.abilities`.
     *
     * `restore`/`force-delete` are Owner-only: they are the two that reach past a soft delete, and
     * the cascade's `forceDelete` maps to the `force-delete` token (hyphenated — the policy method
     * is camelCase, the token is not; {@see BaseModelPolicy::forceDelete()}).
     *
     * @var array<string, list<string>>
     */
    public const DEFAULT_ABILITIES = [
        'owner' => ['view', 'create', 'update', 'delete', 'restore', 'force-delete'],
        'admin' => ['view', 'create', 'update', 'delete'],
        'member' => ['view'],
    ];

    /**
     * Every model class bound to a cascade policy at this host, read off the Gate.
     *
     * @return list<class-string>
     */
    public function policedModels(): array
    {
        $models = [];

        foreach (Gate::policies() as $model => $policy) {
            $policy = (string) $policy;

            if (str_starts_with($policy, 'permission-cascade-policy:')
                || $policy === BaseModelPolicy::class
                || is_subclass_of($policy, BaseModelPolicy::class)) {
                $models[] = $model;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * The abilities `$role` holds, per config. An unknown role gets the empty list rather than a
     * throw: the role vocabulary is the host's data (a stale `beam_memberships.role` string is a
     * fact about the database, not a grammar fault), and this is the same resilient-degrade the
     * entitlement resolver already uses on that column.
     *
     * @return list<string>
     */
    public function abilitiesFor(Role|string $role): array
    {
        $value = $role instanceof Role ? $role->value : $role;

        $configured = config('beam.accounts.roles.abilities', self::DEFAULT_ABILITIES);

        return array_values((array) ($configured[$value] ?? []));
    }

    /**
     * The full permission-token list `$role` should hold: every policed model crossed with that
     * role's abilities.
     *
     * @return list<string>
     */
    public function tokensFor(Role|string $role): array
    {
        $abilities = $this->abilitiesFor($role);

        if ($abilities === []) {
            return [];
        }

        $tokens = [];

        foreach ($this->policedModels() as $model) {
            foreach (PermissionNamer::names($model, $abilities) as $token) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Write the permission rows for `$role` (creating any that don't exist yet) and sync them onto
     * `$roleModel`.
     *
     * `syncPermissions` and not `givePermissionTo`: the token set is derived, so a model whose
     * policy was removed — or an ability a host retiered away — must LOSE its row here rather than
     * accumulate forever. That also makes a re-run converge instead of drifting.
     */
    public function syncTo(object $roleModel, Role|string $role): void
    {
        $tokens = $this->tokensFor($role);

        $permissionModel = config('permission.models.permission');
        $guard = $roleModel->guard_name;

        $permissions = [];

        foreach ($tokens as $token) {
            $permissions[] = $permissionModel::findOrCreate($token, $guard);
        }

        // The registrar caches the whole permission map; rows minted a line ago are invisible to
        // the sync otherwise, which reads as "granted nothing" with no error.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleModel->syncPermissions($permissions);
    }
}
