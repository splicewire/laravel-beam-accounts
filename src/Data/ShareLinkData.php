<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The minter's own share links (ADR-0009, tracer 05/06) — a declarative particle resource:
 * `scope` gates to created_by = me, `project` maps a link to its read shape. Read-only index;
 * revoke is a `share-links.revoke` operation (minter-gated).
 */
#[ParticleResource(key: 'share-links', model: ShareLink::class, filterable: false)]
class ShareLinkData extends Data
{
    public function __construct(
        public int|string $id,
        public string $token,
        public string $scope,
        public string $url,
        public ?string $expiresAt,
        public ?string $revokedAt,
        public int $useCount,
        public ?int $maxUses,
        public bool $isValid,
    ) {}

    public static function scope(Builder $query): Builder
    {
        return $query->where('created_by', (string) Auth::id());
    }

    public static function project(ShareLink $link): self
    {
        return new self(
            id: $link->getKey(),
            token: $link->token,
            scope: $link->scope,
            url: route('beam.share-link.resolve', $link->token),
            expiresAt: $link->expires_at?->toIso8601String(),
            revokedAt: $link->revoked_at?->toIso8601String(),
            useCount: $link->use_count,
            maxUses: $link->max_uses,
            isValid: $link->isValid(),
        );
    }
}
