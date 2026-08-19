<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
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
        // Attachment point only — see AccessGrantData for why the sort key names `created_at`
        // while the attribute sits on `id`. Minting order is the useful default for a link list;
        // `expiresAt` would sort the never-expiring links into one indistinguishable null block.
        #[Sortable(name: 'created_at', column: 'created_at', default: true, direction: 'desc')]
        #[Description('The share-link id.')]
        public int|string $id,
        #[Description('The opaque token in the share URL. Treat it as a secret.')]
        public string $token,
        #[Description('Scope handler key deciding what the link grants access to.')]
        public string $scope,
        #[Description('The full resolve URL a recipient opens.')]
        public string $url,
        #[Description('When the link stops working, ISO-8601; null if it never expires.')]
        public ?string $expiresAt,
        #[Description('When the minter revoked the link, ISO-8601; null if still live.')]
        public ?string $revokedAt,
        #[Description('How many times the link has been resolved.')]
        public int $useCount,
        #[Description('Resolve cap; null for unlimited.')]
        public ?int $maxUses,
        #[Description('Whether the link resolves right now — not revoked, not expired, under any cap.')]
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
