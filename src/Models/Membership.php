<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Accounts\Contracts\MembershipContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
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
        return $this->belongsTo(BeamAccounts::userModel(), 'user_id');
    }

    // --- MembershipContract ------------------------------------------------

    public function memberUser(): Authenticatable
    {
        return $this->user;
    }

    /**
     * `tryFrom`, for the same reason and at the same cost as {@see Team::memberRole()} and
     * {@see \Splicewire\Beam\Accounts\Concerns\HasMembers::memberRole()} — read the long-form note on
     * the latter. `$this->role` is a STORED string this method's caller never chose, and `Role` is
     * closed, so a legacy or hand-seeded value fatals a reader that insists on `from()`.
     *
     * This was the last stored-value reader still on `Role::from()`, and the one the sibling readers
     * deliberately left behind: `MembershipContract:27` declared `: Role`, so converging it meant
     * widening a published interface, which was out of that change's scope. The widening is safe by
     * PHP return-type covariance (an implementer may still declare `: Role`) and the estate has no
     * caller of this method outside this package's own tests, so the scope objection is what expired
     * — not the reasoning behind it.
     *
     * The four CALLER-supplied `Role::from()` sites (`TeamProvisioner:62,76`, `TeamMembers:29,57`)
     * are untouched and still throw. A caller passing a bad literal is grammar its author controls;
     * this is not.
     *
     * The `!== null` arm mirrors the siblings and is not dead: the package's own stub declares the
     * column `NOT NULL DEFAULT 'member'`, but a host is free to declare it nullable, and a model
     * hydrated by a `select()` that omitted `role` has a null attribute regardless of the schema.
     * `Role::tryFrom(null)` is a TypeError, which would reintroduce the fatal this method removes.
     */
    public function memberRole(): ?Role
    {
        return $this->role !== null ? Role::tryFrom($this->role) : null;
    }

    public function isActive(): bool
    {
        return true;
    }
}
