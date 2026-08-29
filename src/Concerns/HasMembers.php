<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Team;

/**
 * The team side of the account primitive over ANY backing table — not just beam's
 * `memberships`. It supplies the {@see TeamContract}
 * role helpers (`hasMember`/`memberRole`/`assignMember`/`removeMember`) in terms of a
 * host-supplied belongsToMany relation and its pivot column names, so a host model on
 * its own table (the app's `Tenant` over `tenant_users`, UUID keys + `removed_at`
 * soft-delete) can satisfy the contract without adopting beam's schema.
 *
 * A host `use`s this trait, points `membersRelation()` at its relation (default
 * `members`), and — only if it diverges from the defaults — overrides the pivot column
 * accessors below. The trait never assumes a table name or key type; it reads through
 * the relation the host already declares. Provisioning, tenancy, and syncing stay the
 * host's private seam.
 *
 * Beam's concrete {@see Team} does NOT use this trait —
 * it keeps its purpose-built `memberships`/`Membership` relations and satisfies the
 * contract directly. The trait exists for hosts whose team lives on a foreign pivot.
 */
trait HasMembers
{
    /**
     * The name of the belongsToMany relation to the member users. Override to point at a
     * differently-named relation (the app's `Tenant` uses `users`).
     */
    protected function membersRelation(): string
    {
        return 'members';
    }

    /**
     * The pivot column holding the role string. Override if the host names it differently.
     */
    protected function memberRoleColumn(): string
    {
        return 'role';
    }

    /**
     * The pivot column that soft-marks a removed seat (nulled = active), or null when the
     * host has no removed lifecycle (a hard-detach model). The app's `Tenant` returns
     * `removed_at`; beam-style hosts return null.
     */
    protected function memberRemovedColumn(): ?string
    {
        return null;
    }

    /**
     * The host's belongsToMany relation to member users, resolved through
     * {@see membersRelation()}.
     */
    protected function membersQuery(): BelongsToMany
    {
        return $this->{$this->membersRelation()}();
    }

    /**
     * Active members — filtered to un-removed seats when the host models removal.
     *
     * @return Collection<int, Authenticatable>
     */
    public function members()
    {
        $query = $this->membersQuery();

        if (($removed = $this->memberRemovedColumn()) !== null) {
            $query->wherePivotNull($removed);
        }

        return $query->get();
    }

    public function hasMember(Authenticatable $user): bool
    {
        $query = $this->membersQuery()
            ->wherePivot($this->membersQuery()->getRelatedPivotKeyName(), $user->getKey());

        if (($removed = $this->memberRemovedColumn()) !== null) {
            $query->wherePivotNull($removed);
        }

        return $query->exists();
    }

    public function memberRole(Authenticatable $user): ?Role
    {
        $member = $this->membersQuery()
            ->wherePivot($this->membersQuery()->getRelatedPivotKeyName(), $user->getKey())
            ->first();

        $value = $member?->getRelationValue('pivot')?->getAttribute($this->memberRoleColumn());

        // `tryFrom`, not `from`, because this reads a value the CALLING author never chose. `Role`
        // is closed (`owner|admin|member`) while the pivot column is a plain string, so the host's
        // database is free to hold a role the enum has never heard of — and does: measured
        // 2026-08-29 at `~/Herd/splicewire-app`, 17 of 42 `tenant_users` rows carry `service` (all
        // one user; `system-tenant-seeding` 02 is the documented author of every one). `from()`
        // threw `ValueError` there, so both membership gates answered a real authorization question
        // with HTTP 500 instead of 403, measured over the wire with the gate proven closed on
        // `DELETE /api/v1/beam/accounts/members/…` and `POST /api/v1/beam/accounts/invitations`.
        // The four CALLER-supplied `Role::from()` sites (`TeamProvisioner:62,76`,
        // `TeamMembers:29,57`) deliberately still throw — that is grammar their author controls.
        //
        // ⚠️ This is CONTAINMENT, NOT RESOLUTION, and the cost is real. It makes `null` mean two
        // things: a `service` seat is now `hasMember() === true` AND `memberRole() === null`, so the
        // two contract methods disagree about whether that user is on the team, and
        // `TeamContract:47`'s own docblock says null means "not a member". Nothing reads it that way
        // today — both consumers are allow-lists that deny cleanly on null (`MembershipPolicy:34`
        // `=== Role::Owner`, `:50` `in_array(…, [Owner, Admin], true)`), and nothing dereferences the
        // return or branches on `!== null` to PERMIT — so nothing breaks. But an ability later
        // written as `if ($team->memberRole($u) === null) { abort(404); }`, a natural reading of the
        // documented contract, would 404 a user who genuinely holds a seat, and the 500 that would
        // have announced the vocabulary was incomplete is gone.
        //
        // The real question is deferred, not answered: is `service` a membership role, or a
        // machine-identity axis wearing the role column? Until that is settled, the divergence is
        // pinned by `tests/ServiceSeatDeniesRatherThanFatalsTest.php` rather than left as prose.
        return $value !== null ? Role::tryFrom($value) : null;
    }

    /**
     * Attach or re-attach a member with a role. Idempotent — an existing seat's role is
     * updated (and a soft-removed seat reactivated where the host models removal).
     */
    public function assignMember(Authenticatable $user, Role $role): void
    {
        $attributes = [$this->memberRoleColumn() => $role->value];

        if (($removed = $this->memberRemovedColumn()) !== null) {
            $attributes[$removed] = null;
        }

        $this->membersQuery()->syncWithoutDetaching([$user->getKey() => $attributes]);
    }

    /**
     * Remove a member — a `removed_at` soft-mark when the host models removal, a hard
     * detach otherwise.
     */
    public function removeMember(Authenticatable $user): void
    {
        if (($removed = $this->memberRemovedColumn()) !== null) {
            $this->membersQuery()->updateExistingPivot($user->getKey(), [$removed => now()]);

            return;
        }

        $this->membersQuery()->detach($user->getKey());
    }
}
