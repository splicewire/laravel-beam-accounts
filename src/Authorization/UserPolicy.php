<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\QueryBuilders\UsersQuery;

/**
 * The write gate for the `users` particle resource.
 *
 * `users` was `readOnly: true`, so it had no write surface and needed no policy. It now accepts an
 * `update` — which makes "edit a user" reachable from the Frame admin and from any host that mounts
 * `Route::particleResource('users', …)` — and that edit must not become "any authenticated seat may
 * rename anyone they can see". The read scope is NOT sufficient on its own here: a principal can
 * legitimately SEE every peer on their teams ({@see UsersQuery::scopeToSharedTeams()}) and must not
 * be able to edit any of them.
 *
 * So the rule is narrower than the read boundary on purpose: **self, or central Root**. Team role is
 * deliberately not consulted — a team Owner administers the TEAM (see {@see MembershipPolicy}), not
 * the identity records of its members, which are central and span teams.
 *
 * Both transports reach this class: the HTTP path through Laravel's policy resolution on the
 * configured user model, the Frame path through the `policy:` slot on {@see UserData}'s
 * `#[ParticleResource]` (which beam wraps in a `PolicyWriteGate`).
 */
class UserPolicy
{
    /**
     * Reading is already gated by the resource's own scope, which runs during resolution — a record
     * the caller may not see never reaches this method. Re-deriving that boundary here as a
     * predicate would be a second, drifting statement of one rule, so this defers to it rather than
     * restating it.
     */
    public function view(?Authenticatable $actor, Model $user): bool
    {
        return $actor !== null;
    }

    /**
     * Users arrive by REGISTRATION and by accepting an invitation, never from an admin create form —
     * minting credentials, dispatching verification mail, and seeding the personal team are all
     * registration's, and none of them is expressible as a generic create.
     */
    public function create(?Authenticatable $actor): bool
    {
        return false;
    }

    public function update(?Authenticatable $actor, Model $user): bool
    {
        return $this->isSelf($actor, $user) || BeamAccounts::isRoot($actor);
    }

    /**
     * Deleting a principal is destructive and cascade-bearing, so the resource ships
     * `deletable: false` and this is unreachable through the generic destroy. It is defined anyway:
     * a host that widens `deletable` inherits the same self-or-Root rule rather than falling through
     * to a policy-less allow.
     */
    public function delete(?Authenticatable $actor, Model $user): bool
    {
        return $this->isSelf($actor, $user) || BeamAccounts::isRoot($actor);
    }

    /**
     * Assume this user's identity. Root-only AND demo-only — the second half is enforced at the op
     * itself, since "are the demo affordances live" is an environment question rather than an
     * authorization one and belongs where the op can 403 with a reason.
     */
    public function loginAs(?Authenticatable $actor, Model $user): bool
    {
        return BeamAccounts::isRoot($actor);
    }

    /**
     * Assume an arbitrary real user's identity — operator impersonation, as distinct from the
     * demo-only {@see self::loginAs()}.
     *
     * Two refusals, both lifted verbatim from the rule audiostud and numero each hand-rolled
     * (particle-identity-resources ticket 03):
     *
     *  - NOT YOURSELF. Harmless but incoherent, and it would write a misleading audit pair.
     *  - NOT ANOTHER STAFF ACCOUNT. This is the real one: impersonating a peer operator would let
     *    staff borrow each other's authority while the audit trail names only the borrowed identity,
     *    so privilege escalation becomes untraceable.
     *
     * WHICH KEY MEANS "STAFF" IS THE HOST'S, via `beam.accounts.impersonation.staff_ability`. The
     * estate default is `entitlement:os.operate` (audiostud's, whose own comment records the
     * `is_staff` column as retired in favour of it) — but numero defines its staff gate
     * `bypass-marquee` AS `$user->is_staff`, so a hardcoded default there would resolve false for
     * every account and quietly make STAFF impersonatable. The check has to follow the host's own
     * notion of staff or it fails open, which is why this is config and not a constant.
     *
     * Deliberately does NOT check that the ACTOR is staff: that is the op's `ability:`, which the
     * host binds to its own operator entitlement. This method answers "may this subject be
     * impersonated", and keeping the two apart is what lets a host re-gate the actor side without
     * re-stating the subject side.
     */
    public function impersonate(?Authenticatable $actor, Model $user): bool
    {
        if ($actor === null || $this->isSelf($actor, $user)) {
            return false;
        }

        $staffAbility = config('beam.accounts.impersonation.staff_ability', 'entitlement:os.operate');

        return ! Gate::forUser($user)->allows($staffAbility);
    }

    /**
     * Identity comparison across the central/tenant split: the principal in tenant context is a
     * TenantUser whose key MIRRORS the central User of record, so comparing keys is correct where
     * comparing object identity or class is not.
     */
    private function isSelf(?Authenticatable $actor, Model $user): bool
    {
        return $actor !== null
            && (string) $actor->getAuthIdentifier() === (string) $user->getKey();
    }
}
