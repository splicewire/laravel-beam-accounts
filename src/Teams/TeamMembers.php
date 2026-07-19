<?php

namespace Splicewire\Beam\Accounts\Teams;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;

/**
 * The multi-member lifecycle over a single-DB team: invite → accept → change role
 * → remove → list. Everything is owner-gated and scoped by team_id (spatie's
 * configurable team key handles the role scoping). No physical tenancy — that stays
 * an opt-in isolation layer above this model.
 */
class TeamMembers
{
    public function __construct(protected TeamProvisioner $provisioner) {}

    public function invite(Team $team, Authenticatable $actor, string $email, Role|string $role = Role::Member): Invitation
    {
        $this->assertOwner($team, $actor);

        $role = $role instanceof Role ? $role : Role::from($role);

        if (! in_array($role, Role::invitable(), true)) {
            throw new AuthorizationException("The {$role->value} role cannot be assigned by invitation.");
        }

        return Invitation::updateOrCreate(
            ['team_id' => $team->getKey(), 'email' => $email],
            ['role' => $role->value, 'token' => (string) Str::uuid()],
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

    public function changeRole(Team $team, Authenticatable $actor, Authenticatable $member, Role|string $role): Membership
    {
        $this->assertOwner($team, $actor);

        $role = $role instanceof Role ? $role : Role::from($role);

        $membership = Membership::where('team_id', $team->getKey())
            ->where('user_id', $member->getKey())
            ->firstOrFail();

        $membership->update(['role' => $role->value]);
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
                ->where('role', Role::Owner->value)
                ->exists();

        if (! $isOwner) {
            throw new AuthorizationException('Only a team owner may manage members.');
        }
    }
}
