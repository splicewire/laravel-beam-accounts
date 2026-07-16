<?php

namespace Schemastud\Beam\Accounts\Teams;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Schemastud\Beam\Accounts\Models\Invitation;
use Schemastud\Beam\Accounts\Models\Membership;
use Schemastud\Beam\Accounts\Models\Team;
use Schemastud\Beam\Accounts\Support\Roles;
use Spatie\Permission\PermissionRegistrar;

/**
 * The multi-member lifecycle over a single-DB team: invite → accept → change role
 * → remove → list. Everything is owner-gated and scoped by team_id (spatie's
 * configurable team key handles the role scoping). No physical tenancy — that stays
 * an opt-in isolation layer above this model.
 */
class TeamMembers
{
    public function __construct(protected TeamProvisioner $provisioner) {}

    public function invite(Team $team, Authenticatable $actor, string $email, string $role = Roles::MEMBER): Invitation
    {
        $this->assertOwner($team, $actor);

        return Invitation::updateOrCreate(
            ['team_id' => $team->getKey(), 'email' => $email],
            ['role' => $role, 'token' => (string) Str::uuid()],
        );
    }

    /**
     * Redeem an invitation for a user, turning it into a real membership.
     */
    public function accept(Invitation $invitation, Authenticatable $user): Membership
    {
        $membership = $this->provisioner->addMember($user, $invitation->team, $invitation->role);

        $invitation->delete();

        return $membership;
    }

    public function changeRole(Team $team, Authenticatable $actor, Authenticatable $member, string $role): Membership
    {
        $this->assertOwner($team, $actor);

        $membership = Membership::where('team_id', $team->getKey())
            ->where('user_id', $member->getKey())
            ->firstOrFail();

        $membership->update(['role' => $role]);
        $this->provisioner->syncSpatieRole($member, $team, $role);

        return $membership;
    }

    public function remove(Team $team, Authenticatable $actor, Authenticatable $member): void
    {
        $this->assertOwner($team, $actor);

        if ($team->user_id == $member->getKey()) {
            throw new AuthorizationException('The team owner cannot be removed.');
        }

        Membership::where('team_id', $team->getKey())
            ->where('user_id', $member->getKey())
            ->delete();

        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($team->getKey());
        $member->syncRoles([]);
        $registrar->setPermissionsTeamId($previous);
    }

    /**
     * @return Collection<int, Membership>
     */
    public function members(Team $team): Collection
    {
        return $team->memberships()->with('user')->get();
    }

    protected function assertOwner(Team $team, Authenticatable $actor): void
    {
        $isOwner = $team->user_id == $actor->getKey()
            || Membership::where('team_id', $team->getKey())
                ->where('user_id', $actor->getKey())
                ->where('role', Roles::OWNER)
                ->exists();

        if (! $isOwner) {
            throw new AuthorizationException('Only a team owner may manage members.');
        }
    }
}
