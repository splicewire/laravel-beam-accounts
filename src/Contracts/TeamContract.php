<?php

namespace Schemastud\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Schemastud\Beam\Accounts\Enums\Role;
use Schemastud\Beam\Accounts\Models\Team;

/**
 * The minimal team surface the account runtime understands: an object that has an
 * owner, members, a role for each member, and the ability to assign/remove members
 * and read pending invitations.
 *
 * This is the shared primitive — the one thing a beam `Team` (its own `memberships`
 * table) and a platform host model (the app's `Tenant`, over `tenant_users`) both
 * satisfy. It says nothing about the backing table, key type, or provisioning: those
 * are the host's private seam. The reference implementation is
 * {@see Team}; the app's `Tenant` implements the
 * same contract over `tenant_users` with UUID keys and a `removed_at` soft-delete.
 */
interface TeamContract
{
    /**
     * The team's identity — whatever value scopes its memberships and roles (a bigint
     * key on beam's `Team`, the tenant id string on the app's `Tenant`). Callers treat
     * it as opaque.
     */
    public function teamKey(): int|string;

    /**
     * The active members of this team as a collection of Authenticatable users. Excludes
     * removed/soft-deleted seats where the host models them.
     *
     * @return Collection<int, Authenticatable>
     */
    public function members();

    /**
     * True when the given user is an active member of this team.
     */
    public function hasMember(Authenticatable $user): bool;

    /**
     * The role the given user holds on this team, or null when they are not a member.
     */
    public function memberRole(Authenticatable $user): ?Role;

    /**
     * Attach (or re-attach) a user to this team with a role. Idempotent — an existing
     * member's role is updated. The host owns how this lands (pivot upsert, membership
     * row, spatie sync).
     */
    public function assignMember(Authenticatable $user, Role $role): void;

    /**
     * Remove a user from this team. The host owns the mechanics (a hard delete on beam's
     * `memberships`, a `removed_at` soft-delete on the app's `tenant_users`).
     */
    public function removeMember(Authenticatable $user): void;

    /**
     * The pending (unaccepted) invitations for this team.
     *
     * @return Collection<int, MembershipContract|object>
     */
    public function invitations();
}
