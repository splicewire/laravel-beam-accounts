<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Splicewire\Beam\Accounts\Concerns\HasMembers;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Models\Invitation;

/**
 * A host team on a FOREIGN pivot — the shape {@see HasMembers} exists to serve, and the shape a
 * host reaches through `beam.accounts.teams.resolver` (beam-facade 167).
 *
 * Deliberately NOT beam's own `Team`: string primary key, non-incrementing, its member relation
 * named `users` rather than `members`, and a `removed_at` soft-removal column. That is the
 * flagship's `Splicewire\Beam\Tenancy\Tenant` over `tenant_users` reproduced without a tenancy
 * dependency — the only binding of the resolver seam in the estate (measured 2026-08-29: the
 * string `beam.accounts.teams.resolver` occurred 6 times across every `~/Herd` root, both starters
 * and every first-party package root — this file and its test add two more — and exactly one of the
 * six was a binding:
 * `~/Herd/splicewire-app/app/Providers/TenancyServiceProvider.php:203`).
 *
 * It is a fixture rather than a second production shape on purpose: the package must be able to
 * fail when the resolver path breaks, without waiting for a host suite to run.
 */
class HostTeam extends Model implements TeamContract
{
    use HasMembers;

    protected $table = 'host_teams';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * The member relation is named `users`, not `members` — the trait must be pointed at it.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'host_team_users', 'host_team_id', 'user_id')
            ->withPivot(['role', 'removed_at'])
            ->withTimestamps();
    }

    public function teamKey(): int|string
    {
        return $this->getKey();
    }

    /**
     * @return Collection<int, Invitation>
     */
    public function invitations()
    {
        return Invitation::query()->where('team_id', $this->getKey())->get();
    }

    protected function membersRelation(): string
    {
        return 'users';
    }

    protected function memberRemovedColumn(): ?string
    {
        return 'removed_at';
    }
}
