<?php

namespace Schemastud\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Schemastud\Beam\Accounts\Enums\Role;
use Schemastud\Beam\Accounts\Models\Membership;

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
     * The role held on the team.
     */
    public function memberRole(): Role;

    /**
     * True when the seat is currently active (accepted and not removed). Beam's simple
     * `memberships` row is always active; a host that models an invite/removed lifecycle
     * (the app's `tenant_users`) reports the real state.
     */
    public function isActive(): bool;
}
