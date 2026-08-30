<?php

namespace Splicewire\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;

/**
 * The minimal membership surface: a single user's seat on a team — who they are, the
 * role they hold, and whether the seat is currently active.
 *
 * The reference implementation is {@see Membership}
 * (a row on beam's `memberships` table). The app's `TenantUser` aligns to these
 * semantics over its own `tenant_users` pivot (an `accepted_at`/`removed_at` seat).
 */
interface MembershipContract
{
    /**
     * The user this membership belongs to.
     */
    public function memberUser(): Authenticatable;

    /**
     * The role held on the team, or `null` when the stored value is outside {@see Role}'s vocabulary.
     *
     * ⚠️ **Null means "unparseable", and it means ONLY that.** Unlike {@see TeamContract::memberRole()},
     * which is asked about a user who may not be on the team at all and whose null is documented as
     * "not a member", this method is asked OF a seat that already exists — you are holding the row. So
     * a null here is never a statement about membership; {@see self::isActive()} remains the only
     * answer to "is this seat live", and it is unaffected.
     *
     * The signature was widened from `: Role` at the same time `Membership::memberRole()` moved from
     * `Role::from()` to `Role::tryFrom()`. `Role` is closed (`owner|admin|member`) while the role
     * column is a plain string at every host that stores one, so the database is free to hold a value
     * the enum has never heard of — and does: 3 live rows in the estate on record (ticket 164), and 17
     * of 42 `tenant_users` rows at `~/Herd/splicewire-app` carry `service`. `from()` threw a
     * `ValueError` on those, i.e. answered an authorization question with HTTP 500 instead of a denial.
     *
     * Widening an interface's return type is safe for implementers by PHP's return-type COVARIANCE: an
     * implementation may declare the narrower `: Role` and still satisfy `: ?Role`. So no host that
     * implements this contract has to change. What CAN break is a caller that dereferences the result;
     * measured 2026-08-30 across the package roots, `~/Herd` app dirs and the starters,
     * this package's own {@see \Splicewire\Beam\Accounts\Tests\ContractConformanceTest} is the estate's
     * only caller, so the widening reaches nothing that could have been holding a `Role`.
     */
    public function memberRole(): ?Role;

    /**
     * True when the seat is currently active (accepted and not removed). Beam's simple
     * `memberships` row is always active; a host that models an invite/removed lifecycle
     * (the app's `tenant_users`) reports the real state.
     */
    public function isActive(): bool;
}
