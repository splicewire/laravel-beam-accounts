<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Models\AccessGrant;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * "Shared with me" (ADR-0009, tracer 04) — the directory-ACL grants where the current user is the
 * grantee, as a declarative particle resource. Points at the OOTB AccessGrant model; a host that
 * binds a different grant_model registers its own read resource.
 */
#[ParticleResource(key: 'access-grants', backing: AccessGrant::class, filterable: false)]
class AccessGrantData extends Data
{
    public function __construct(
        // The default sort rides `id` only as an attachment point — `#[Sortable]` targets a
        // PROPERTY, and this read shape projects no timestamp. `name`/`column` both say
        // `created_at`, so the public sort key and the ORDER BY are the grant's age, not its id.
        // Widening the shape just to hang the attribute on a `createdAt` would change the wire.
        #[Sortable(name: 'created_at', column: 'created_at', default: true, direction: 'desc')]
        #[Description('The grant id.')]
        public int|string $id,
        #[Description('Morph alias of the granted record, e.g. the model type shared with you.')]
        public string $grantableType,
        #[Description('Key of the granted record, as a string.')]
        public string $grantableId,
        #[Description('The ability the grant confers, e.g. view.')]
        public string $ability,
        #[Description('Whether the grant allows or denies. A deny wins over an allow.')]
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
            grantableType: $grant->grantable_type,
            grantableId: $grant->grantable_id,
            ability: $grant->ability,
            effect: $grant->effect,
        );
    }
}
