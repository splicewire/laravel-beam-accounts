<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Teams\TeamMembers;

/**
 * The one authorization seam for managing a team's membership — the owner-gated
 * lifecycle (invite / change role / remove). Registered as the `manageMembers`
 * gate ability by {@see BeamAccountsServiceProvider}, so
 * both the engine's own {@see TeamMembers} flow and
 * any host controller authorize through the SAME named check —
 * `$user->can('manageMembers', $team)` — instead of hand-rolling `role === Owner`.
 *
 * Contract-based: it reads the actor's role through {@see TeamContract::memberRole()},
 * so it holds for both beam's concrete `Team` (its own `memberships`) and a host team
 * on a foreign pivot (the app's `Tenant` over `tenant_users`) without knowing the
 * backing table.
 */
class MembershipPolicy
{
    /**
     * Whether the user may manage the team's members (owner-only). Membership
     * management is a team-role concern, deliberately distinct from tenant-scoped
     * resource permissions — see the app CONTEXT.md "permission vs entitlement vs
     * team role" glossary and the "permission token prefix is the morph alias" ADR.
     */
    public function manageMembers(?Authenticatable $user, TeamContract $team): bool
    {
        return $user !== null && $team->memberRole($user) === Role::Owner;
    }

    /**
     * Whether the user may manage the team's *invitations* — send an invite, resend
     * it, or revoke a pending one. This is the graduated tier of the same membership
     * axis: it admits owners AND admins, where {@see self::manageMembers} (change role /
     * remove / ownership transfer) stays owner-only. Splitting the two lets an admin
     * grow the team without handing them the high-stakes acts, and moves the invite
     * gate off the host controller's hand-rolled `in_array([Owner, Admin])` onto the
     * same policy seam — one named check (`$user->can('manageInvitations', $team)`),
     * not a second definition.
     */
    public function manageInvitations(?Authenticatable $user, TeamContract $team): bool
    {
        return $user !== null
            && in_array($team->memberRole($user), [Role::Owner, Role::Admin], true);
    }
}
