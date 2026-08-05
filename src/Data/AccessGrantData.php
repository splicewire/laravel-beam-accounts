<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * "Shared with me" (ADR-0009, tracer 04) — the directory-ACL grants where the current user is the
 * grantee, as a declarative particle resource. Points at the OOTB AccessGrant model; a host that
 * binds a different grant_model registers its own read resource.
 */
#[ParticleResource(key: 'access-grants', model: AccessGrant::class, filterable: false)]
class AccessGrantData extends Data
{
    public function __construct(
        public int|string $id,
        public string $grantable_type,
        public string $grantable_id,
        public string $ability,
        public string $effect,
    ) {}

    public static function scope(Builder $query): Builder
    {
        $actor = Auth::user();

        return $query
            ->where('grantee_type', $actor?->getMorphClass() ?? 'user')
            ->where('grantee_id', (string) Auth::id());
    }

    public static function project(Model $grant): self
    {
        return new self(
            id: $grant->getKey(),
            grantable_type: $grant->grantable_type,
            grantable_id: $grant->grantable_id,
            ability: $grant->ability,
            effect: $grant->effect,
        );
    }
}
