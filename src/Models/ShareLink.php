<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Splicewire\Beam\Beam;

/**
 * A reusable, revocable, expiring capability link (ADR-0009, tracer 05) — the primitive that
 * makes "unlisted / link-only" access work without installing tower's GuestToken. A signed
 * token grants scoped access to an otherwise-private resource; the consuming satellite (tracer
 * 06) resolves `/s/{token}` against it. Mirrors {@see Invitation}'s token + `Beam::table()`
 * prefix pattern; unlike an invitation it is NOT team-scoped — `scope` is an opaque host string
 * (e.g. `composition:{uuid}`) the resolver interprets.
 *
 * @property string $token
 * @property string $scope
 * @property string|null $created_by minter's user key (string — cross-host: uuid or bigint)
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int $use_count
 * @property int|null $max_uses
 */
class ShareLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'use_count' => 'integer',
            'max_uses' => 'integer',
        ];
    }

    /** `share_links` → `beam_share_links`, via the single table-prefix seam {@see Beam::table()}. */
    public function getTable(): string
    {
        return Beam::table('share_links');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasUsesLeft(): bool
    {
        return $this->max_uses === null || $this->use_count < $this->max_uses;
    }

    /** A link is usable while not revoked, not expired, and under its use cap. */
    public function isValid(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && $this->hasUsesLeft();
    }
}
