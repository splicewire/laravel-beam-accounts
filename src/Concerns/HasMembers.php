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
     * The pivot column holding the moment a seat became active — what the `members` list renders as
     * "Joined". PUBLIC, unlike its siblings above, because the reader is
     * {@see \Splicewire\Beam\Accounts\Frame\Sources\MembershipSource} rather than this trait: the
     * source projects the pivot row and has to ask the team which column carries that fact.
     *
     * Default `created_at`, the pivot's own row age — correct for a host that simply attaches a seat.
     * A host with an invite lifecycle names the column its acceptance stamps: `~/Herd/splicewire-app`'s
     * `Tenant` returns `accepted_at`.
     *
     * ⚠️ Getting this wrong is SILENT. A `belongsToMany` only materialises the pivot columns its
     * `withPivot()` names, so an absent column reads as `null` rather than as an error — and `joinedAt`
     * simply renders empty. Measured 2026-09-01: `Tenant::users()` declares
     * `withPivot('role', 'invited_at', 'accepted_at', 'removed_at')` and no `created_at`, so the
     * hardcoded `created_at` this seam replaces would have blanked the Joined column at the flagship the
     * moment tower's own membership source was retired.
     */
    public function memberJoinedColumn(): string
    {
        return 'created_at';
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

    /**
     * The role of an active member, or null when there is no active seat or the stored role is unknown.
     * Hosts that soft-mark removal must not expose a removed seat's old role to authorization callers.
     */
    public function memberRole(Authenticatable $user): ?Role
    {
        $query = $this->membersQuery()
            ->wherePivot($this->membersQuery()->getRelatedPivotKeyName(), $user->getKey());

        if (($removed = $this->memberRemovedColumn()) !== null) {
            $query->wherePivotNull($removed);
        }

        $member = $query->first();
        $value = $member?->getRelationValue('pivot')?->getAttribute($this->memberRoleColumn());

        // `tryFrom`, not `from`, because this reads a value the CALLING author never chose. `Role`
        // is closed (`owner|admin|member`) while the pivot column is a plain string, so the host's
        // database may hold a role the enum does not know. Keep that containment, and apply the
        // active-seat filter above so a removed row cannot authorize through its historical role.
        //
        // An unrecognized role can still make `hasMember()` true while this returns null. That is a
        // separate vocabulary question; null here also means no active seat, matching the contract's
        // authorization use.
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
