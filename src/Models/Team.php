<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Facades\Beam;

/**
 * The reference implementation of {@see TeamContract} — a single-DB team over beam's
 * own `beam_memberships` table. Behavior is unchanged from before the contract was
 * introduced; the interface just names the surface the account runtime already used.
 */
class Team extends Model implements TeamContract
{
    protected $guarded = [];

    protected $casts = [
        'personal_team' => 'boolean',
    ];

    /**
     * `teams` → `beam_teams`, routed through the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename ticket 04). A property default cannot call config(), so the prefix is
     * applied here.
     */
    public function getTable(): string
    {
        return Beam::table('teams');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(BeamAccounts::userModel(), 'user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function members()
    {
        return $this->belongsToMany(BeamAccounts::userModel(), Beam::table('memberships'))
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    // --- TeamContract ------------------------------------------------------

    public function teamKey(): int|string
    {
        return $this->getKey();
    }

    public function hasMember(Authenticatable $user): bool
    {
        return $this->memberships()->where('user_id', $user->getKey())->exists();
    }

    public function memberRole(Authenticatable $user): ?Role
    {
        $value = $this->memberships()
            ->where('user_id', $user->getKey())
            ->value('role');

        return $value !== null ? Role::from($value) : null;
    }

    public function assignMember(Authenticatable $user, Role $role): void
    {
        Membership::updateOrCreate(
            ['team_id' => $this->getKey(), 'user_id' => $user->getKey()],
            ['role' => $role->value],
        );
    }

    public function removeMember(Authenticatable $user): void
    {
        $this->memberships()->where('user_id', $user->getKey())->delete();
    }
}
