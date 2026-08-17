<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function Splicewire\Beam\Accounts\accountUserModel;

use Splicewire\Beam\Accounts\Contracts\MembershipContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Facades\Beam;

/**
 * The reference implementation of {@see MembershipContract} — a single seat row on
 * beam's `beam_memberships` table. A beam membership is always active (no invite/removed
 * lifecycle on this table); a host that models one reports its real state.
 */
class Membership extends Model implements MembershipContract
{
    protected $guarded = [];

    /**
     * `memberships` → `beam_memberships`, via the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename ticket 04).
     */
    public function getTable(): string
    {
        return Beam::table('memberships');
    }

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
