<?php

namespace Schemastud\Beam\Accounts\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Schemastud\Beam\Accounts\Models\Membership;
use Schemastud\Beam\Accounts\Models\Team;

/**
 * The satellite end-user's side of the teams-first model. Every account owns a
 * personal team (team-of-one) and may belong to others; `current_team_id` names
 * the team whose permission scope is active.
 */
trait BelongsToTeams
{
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function personalTeam(): ?Team
    {
        return $this->ownedTeams()->where('personal_team', true)->first();
    }

    public function currentTeamOrPersonal(): ?Team
    {
        return $this->currentTeam ?? $this->personalTeam();
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->memberships()->where('team_id', $team->id)->exists();
    }

    public function teamRole(Team $team): ?string
    {
        return $this->memberships()->where('team_id', $team->id)->value('role');
    }

    public function switchTeam(Team $team): void
    {
        $this->forceFill(['current_team_id' => $team->id])->save();
    }
}
