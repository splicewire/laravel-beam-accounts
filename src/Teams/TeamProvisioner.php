<?php

namespace Schemastud\Beam\Accounts\Teams;

use Illuminate\Contracts\Auth\Authenticatable;

use function Schemastud\Beam\Accounts\accountGuard;

use Schemastud\Beam\Accounts\Models\Membership;
use Schemastud\Beam\Accounts\Models\Team;
use Schemastud\Beam\Accounts\Support\Roles;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns a freshly-registered user into a team-of-one: a personal team, an owner
 * membership, the owner role scoped to that team (spatie teams-mode), and the
 * team set current. Multi-member growth lives in issue 04.
 */
class TeamProvisioner
{
    public function personalTeamFor(Authenticatable $user, ?string $name = null): Team
    {
        $team = Team::create([
            'user_id' => $user->getKey(),
            'name' => $name ?? $this->personalTeamName($user),
            'personal_team' => true,
        ]);

        $this->addMember($user, $team, Roles::OWNER);
        $user->forceFill(['current_team_id' => $team->getKey()])->save();

        return $team;
    }

    /**
     * Attach a user to a team with a role, mirroring the assignment into spatie's
     * team-scoped role so the permission cascade resolves against it.
     */
    public function addMember(Authenticatable $user, Team $team, string $role): Membership
    {
        $membership = Membership::updateOrCreate(
            ['team_id' => $team->getKey(), 'user_id' => $user->getKey()],
            ['role' => $role],
        );

        $this->syncSpatieRole($user, $team, $role);

        return $membership;
    }

    public function syncSpatieRole(Authenticatable $user, Team $team, string $role): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($team->getKey());

        $roleModel = app(config('permission.models.role'))::findOrCreate($role, accountGuard());
        $user->syncRoles([$roleModel]);

        $registrar->setPermissionsTeamId($previous);
    }

    protected function personalTeamName(Authenticatable $user): string
    {
        $template = config('splicewire.account.personal_team_name', "{name}'s Team");

        return str_replace('{name}', (string) ($user->name ?? 'Personal'), $template);
    }
}
