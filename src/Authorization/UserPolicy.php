<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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
