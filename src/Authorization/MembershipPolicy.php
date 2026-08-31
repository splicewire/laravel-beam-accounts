<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Invitation;
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

    /**
     * The same decision, RE-SUBJECTED onto one {@see Invitation} — what a particle operation on an
     * invitation has to declare, because `ability:` is checked against the subject the operation
     * RESOLVED and that subject is the invitation, not the team.
     *
     * ## It replaces beam-tenancy's `manageTenantInvitations`
     *
     * That ability existed for exactly this reason and could only be written in beam-tenancy,
     * because it had to reach the invitation's team through a `TenantInvitation::tenant()` relation
     * — a fact about one host's model. With one packaged `Invitation` whose `team_id` is a
     * `TeamContract` key rather than a row in a named table, there is no relation to reach through
     * and the check belongs here, beside the ability it delegates to.
     *
     * ## The team is the RESOLVED one, and the invitation must belong to it
     *
     * `team_id` is an opaque key, so this cannot load the team from it — nothing in the package
     * knows which table to look in. It asks {@see BeamAccounts::currentTeam()} instead (the current
     * -or-personal team by default; the tenant at a host that binds `beam.accounts.teams.resolver`)
     * and requires the invitation to be one of ITS invitations before delegating.
     *
     * ⚠️ That ownership check is a real, deliberate tightening over `manageTenantInvitations`, which
     * asked about the invitation's OWN tenant and so would authorize an owner of tenant A acting on
     * tenant A's invitation from a mount mounted in tenant B. Every live mount is team-scoped and
     * the resource `scope` already pins the same fact, so no reachable call changes answer — but the
     * check no longer depends on the scope having been applied first.
     *
     * ⚠️ It denies when there is no resolved team. An operation on an invitation mounted where no
     * team can be resolved is not a case this ability can answer, and answering it from the
     * invitation's own key would be trusting the URL to name its own authorizer.
     *
     * The string comparison is deliberate: `teamKey()` is `int|string` and {@see Invitation} casts
     * `team_id` to string, so this is the one place both sides have to be normalized.
     */
    public function manageInvitation(?Authenticatable $user, Invitation $invitation): bool
    {
        $team = BeamAccounts::currentTeam();

        return $user !== null
            && $team instanceof TeamContract
            && (string) $team->teamKey() === (string) $invitation->team_id
            && $this->manageInvitations($user, $team);
    }
}
