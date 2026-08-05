<?php

namespace Splicewire\Beam\Accounts\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * "My access requests" (ADR-0009, tracer 04) — the view-requests the current user filed, with
 * status, as a declarative particle resource. Owner-side (incoming) requests stay per-resource.
 */
#[ParticleResource(key: 'view-requests', model: ViewRequest::class, filterable: false)]
class ViewRequestData extends Data
{
    public function __construct(
        public string $id,
        public string $requestableType,
        public string $requestableId,
        public string $status,
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
