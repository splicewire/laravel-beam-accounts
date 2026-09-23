<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * "My access requests" (ADR-0009, tracer 04) — the view-requests the current user filed, with
 * status, as a declarative particle resource. Owner-side (incoming) requests stay per-resource.
 * This ledger is list-only: requesting and deciding access belong to the sharing operations.
 */
#[ParticleResource(key: 'view-requests', backing: ViewRequest::class, readOnly: true, showable: false)]
class ViewRequestData extends BeamData
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
        #[Description('Where the request stands: pending, approved, or declined.')]
        public string $status,
        #[Description('When the owner decided, ISO-8601; null while the request is pending.')]
        public ?string $decidedAt,
    ) {}

    public static function scope(Builder $query): Builder
    {
        $actor = Auth::user();
        $id = $actor?->getAuthIdentifier();

        if ($id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('requester_type', $actor->getMorphClass())
            ->where('requester_id', (string) $id);
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
