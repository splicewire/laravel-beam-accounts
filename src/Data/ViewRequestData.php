<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * "My access requests" (ADR-0009, tracer 04) — the view-requests the current user filed, with
 * status, as a declarative particle resource. Owner-side (incoming) requests stay per-resource.
 */
#[ParticleResource(key: 'view-requests', backing: ViewRequest::class, filterable: false)]
class ViewRequestData extends Data
{
    public function __construct(
        // Attachment point only — see AccessGrantData. `created_at` rather than `decidedAt`
        // because this id is a uuid (no insertion order in it) and `decidedAt` is null for every
        // still-pending request, which is exactly the set a requester most wants at the top.
        #[Sortable(name: 'created_at', column: 'created_at', default: true, direction: 'desc')]
        #[Description('The view-request id.')]
        public string $id,
        #[Description('Morph alias of the record access was requested to.')]
        public string $requestableType,
        #[Description('Key of the record access was requested to, as a string.')]
        public string $requestableId,
        #[Description('Where the request stands: pending, approved, or denied.')]
        public string $status,
        #[Description('When the owner decided, ISO-8601; null while the request is pending.')]
        public ?string $decidedAt,
    ) {}

    public static function scope(Builder $query): Builder
    {
        $actor = Auth::user();

        return $query
            ->where('requester_type', $actor?->getMorphClass() ?? 'user')
            ->where('requester_id', (string) Auth::id());
    }

    public static function project(ViewRequest $request): self
    {
        return new self(
            id: $request->id,
            requestableType: $request->requestable_type,
            requestableId: $request->requestable_id,
            status: $request->status,
            decidedAt: $request->decided_at?->toIso8601String(),
        );
    }
}
