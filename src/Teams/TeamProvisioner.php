<?php

namespace Splicewire\Beam\Accounts\Teams;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\PermissionRegistrar;
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
    public function __construct(private RealmReachGrant $reach) {}

    public function personalTeamFor(Authenticatable $user, ?string $name = null): Team
    {
        $team = Team::create([
            'user_id' => $user->getKey(),
            'name' => $name ?? $this->personalTeamName($user),
            'personal_team' => true,
        ]);

        $this->addMember($user, $team, Role::Owner);
        $user->forceFill(['current_team_id' => $team->getKey()])->save();

        return $team;
    }

    /**
     * `$user` becomes Owner of their own personal Team holding `manage` on every provisioned realm's
     * root ({@see RealmReachGrant}) — the grant-cascade equivalent of a blanket `is_staff` grant
     * (theme-entries-and-authoring). `updateOrCreate` on the user's own personal team (not
     * {@see personalTeamFor()}'s plain `create`) so this is idempotent — safe to call from a factory
     * state or a reseed without minting a second orphaned team.
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

        $registrar->setPermissionsTeamId($previous);
    }

    protected function personalTeamName(Authenticatable $user): string
    {
        $template = config('beam.accounts.personal_team_name', "{name}'s Team");

        return str_replace('{name}', (string) ($user->name ?? 'Personal'), $template);
    }
}
