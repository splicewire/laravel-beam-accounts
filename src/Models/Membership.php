<?php

namespace Schemastud\Beam\Accounts\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function Schemastud\Beam\Accounts\accountUserModel;

use Schemastud\Beam\Accounts\Contracts\MembershipContract;
use Schemastud\Beam\Accounts\Enums\Role;

/**
 * The reference implementation of {@see MembershipContract} — a single seat row on
 * beam's `memberships` table. A beam membership is always active (no invite/removed
 * lifecycle on this table); a host that models one reports its real state.
 */
class Membership extends Model implements MembershipContract
{
    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(accountUserModel(), 'user_id');
    }

    // --- MembershipContract ------------------------------------------------

    public function memberUser(): Authenticatable
    {
        return $this->user;
    }

    public function memberRole(): Role
    {
        return Role::from($this->role);
    }

    public function isActive(): bool
    {
        return true;
    }
}
