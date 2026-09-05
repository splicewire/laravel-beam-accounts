<?php

namespace Splicewire\Beam\Accounts\Teams;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Authorization\RolePermissions;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;

/**
 * Turns a freshly-registered user into a team-of-one: a personal team, an owner
 * membership, the owner role scoped to that team (spatie teams-mode), and the
 * team set current. Multi-member growth lives in issue 04.
 */
class TeamProvisioner
{
    public function __construct(private RealmReachGrant $reach, private RolePermissions $rolePermissions) {}

    /**
     * `updateOrCreate` keyed on the user's own personal team, so a re-seed re-uses it instead of
     * minting a second one. It was a plain `Team::create()` until 2026-09-05, and `DemoTeamSeeder`
     * — whose docblock claims idempotence — calls this: three install runs at `~/Herd/beam` left
     * three personal teams for one user, with live memberships and team-scoped role rows pointing
     * at the orphans and `current_team_id` repointed to the newest.
     * `tests/DemoSeedIdempotenceTest.php` runs the seeder TWICE; a single run passes either way,
     * which is why a one-run test would have certified the defect.
     *
     * Two things this does NOT do. It does not clean up duplicates a host already minted — the
     * `firstOrNew` behind `updateOrCreate` binds to whichever row comes back first and leaves the
     * rest permanently orphaned, so an existing host needs a one-off data repair as well. And it
     * rewrites `name` on every call, so a user-renamed personal team reverts to the default on a
     * re-provision; latent today (nothing renames a personal team) and inherited from the sibling.
     */
    public function personalTeamFor(Authenticatable $user, ?string $name = null): Team
    {
        $team = Team::updateOrCreate(
            ['user_id' => $user->getKey(), 'personal_team' => true],
            ['name' => $name ?? $this->personalTeamName($user)],
        );

        $this->addMember($user, $team, Role::Owner);
        $user->forceFill(['current_team_id' => $team->getKey()])->save();

        return $team;
    }

    /**
     * `$user` becomes Owner of their own personal Team holding `manage` on every provisioned realm's
     * root ({@see RealmReachGrant}) — the grant-cascade equivalent of a blanket `is_staff` grant
     * (theme-entries-and-authoring). `updateOrCreate` on the user's own personal team so this is
     * idempotent — safe to call from a factory state or a reseed without minting a second orphaned
     * team. {@see personalTeamFor()} carried a plain `create` until 2026-09-05 and now writes the
     * same row the same way; the two still differ in that this one PRESERVES an existing
     * `current_team_id` rather than repointing it (a user who has switched teams stays where they
     * were), and grants the realm reach above — which is the whole reason it exists.
     */
    public function personalTeamWithFullReachFor(Authenticatable $user, ?string $name = null): Team
    {
        $team = Team::updateOrCreate(
            ['user_id' => $user->getKey(), 'personal_team' => true],
            ['name' => $name ?? $this->personalTeamName($user)],
        );

        $this->addMember($user, $team, Role::Owner);
        $user->forceFill(['current_team_id' => $user->getAttribute('current_team_id') ?? $team->getKey()])->save();
        $this->reach->toTeam($team);

        return $team;
    }

    /**
     * Attach a user to a team with a role, mirroring the assignment into spatie's
     * team-scoped role so the permission cascade resolves against it.
     */
    public function addMember(Authenticatable $user, Team $team, Role|string $role): Membership
    {
        $role = $role instanceof Role ? $role : Role::from($role);

        $membership = Membership::updateOrCreate(
            ['team_id' => $team->getKey(), 'user_id' => $user->getKey()],
            ['role' => $role->value],
        );

        $this->syncSpatieRole($user, $team, $role);

        return $membership;
    }

    public function syncSpatieRole(Authenticatable $user, Team $team, Role|string $role): void
    {
        $role = $role instanceof Role ? $role : Role::from($role);

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($team->getKey());

        $roleModel = app(config('permission.models.role'))::findOrCreate($role->value, BeamAccounts::guard());
        $user->syncRoles([$roleModel]);

        // The role row exists but holds no permissions until something writes them, and until
        // 2026-09-05 nothing in the family did — so `BaseModelPolicy::viewAny()` denied every
        // cascade-policed resource to every principal, team owner included. This is the one place a
        // team-scoped role row is created, so it is where the tokens are attached: every team gets a
        // working authorization model at the moment it has a role, not only where a host remembered
        // to run a seeder. {@see RolePermissions} derives the token set from the Gate's policy map.
        $this->rolePermissions->syncTo($roleModel, $role);

        $registrar->setPermissionsTeamId($previous);
    }

    protected function personalTeamName(Authenticatable $user): string
    {
        $template = config('beam.accounts.personal_team_name', "{name}'s Team");

        return str_replace('{name}', (string) ($user->name ?? 'Personal'), $template);
    }
}
