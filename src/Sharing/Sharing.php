<?php

namespace Splicewire\Beam\Accounts\Sharing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Data\AccessGrantData;
use Splicewire\Beam\Accounts\Data\ViewRequestData;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * Attachable sharing (ADR-0009, tracer 04 generalized): one call gives ANY beam particle
 * resource the full sharing capability over its HasVisibility model — share/unshare (AccessGrant)
 * and the request-access → approve/decline view-request flow. All five verbs are model-agnostic
 * particle operations.
 *
 * Usage (in a host provider boot):
 *   Sharing::attachTo('songs', Composition::class, [
 *       'urlKey' => 'songs', 'groupPrefix' => 'resources',
 *       'middleware' => ['web', 'auth', 'not-suspended'],
 *   ]);
 */
class Sharing
{
    /**
     * @param  string  $resourceKey  the particle resource key the operations attach to
     * @param  class-string<Model>  $model  the HasVisibility model shared
     */
    public static function attachTo(string $resourceKey, string $model, array $opts = []): void
    {
        $urlKey = $opts['urlKey'] ?? $resourceKey;
        $groupPrefix = $opts['groupPrefix'] ?? 'resources';
        $middleware = $opts['middleware'] ?? ['web', 'auth'];
        $manage = $opts['manageAbility'] ?? 'update';
        $requestAbility = $opts['requestAbility'] ?? "request-{$resourceKey}-access";

        // Any authenticated non-owner may request access (unless the host supplied its own ability).
        if (! isset($opts['requestAbility'])) {
            Gate::define($requestAbility, fn ($user, Model $resource) => ($resource->user_id ?? null) != $user->getKey());
        }

        // The five sharing verbs are inline particle operations. `Route::particleOps` (HTTP-02) registers each
        // inline object AND mounts it in one loop-collapsed call — the caller keeps its own middleware/prefix
        // `group()`. (Was: imperative `$registry->register(...)` + a hand-rolled `foreach → particleOp`.)
        $ops = [
            new ParticleOperation(
                resource: $resourceKey, name: 'share', kind: OperationKind::Write, model: $model, ability: $manage,
                handle: function (Model $resource, Request $request) {
                    $data = $request->validate([
                        'recipient_id' => ['required'],
                        'ability' => ['nullable', 'in:view,manage'],
                    ]);
                    app(AccessGrants::class)->share($resource, self::resolveUser($data['recipient_id']), $data['ability'] ?? AccessGrant::ABILITY_VIEW);

                    return ['data' => ['id' => $resource->getKey(), 'shared_with' => $data['recipient_id']]];
                },
            ),
            new ParticleOperation(
                resource: $resourceKey, name: 'unshare', kind: OperationKind::Write, model: $model, ability: $manage,
                handle: function (Model $resource, Request $request) {
                    $data = $request->validate([
                        'recipient_id' => ['required'],
                        'ability' => ['nullable', 'in:view,manage'],
                    ]);
                    $removed = app(AccessGrants::class)->revoke($resource, self::resolveUser($data['recipient_id']), $data['ability'] ?? null);

                    return ['data' => ['id' => $resource->getKey(), 'revoked' => $removed]];
                },
            ),
            new ParticleOperation(
                resource: $resourceKey, name: 'request-access', kind: OperationKind::Write, model: $model, ability: $requestAbility,
                handle: function (Model $resource, Request $request, $actor) {
                    $viewRequest = app(ViewRequests::class)->request($resource, $actor);

                    return ['data' => ['id' => $viewRequest->id, 'status' => $viewRequest->status]];
                },
            ),
        ];

        foreach (['approve' => true, 'decline' => false] as $verb => $approve) {
            $ops[] = new ParticleOperation(
                resource: $resourceKey, name: "{$verb}-request", kind: OperationKind::Write, model: $model, ability: $manage,
                handle: fn (Model $resource, Request $request) => self::decide($resource, $request, $approve),
            );
        }

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () use ($urlKey, $resourceKey, $ops) {
            Particle::ops($urlKey, $resourceKey, $ops);
        });
    }

    /**
     * Register + mount the two sharing-ledger READ resources — the current user's own view of
     * each ledger (ADR-0009): `access-grants` (grantee = me — "shared with me") and
     * `view-requests` (requester = me — "my requests + status").
     * Owner-side management (grants ON my resources / requests FOR my resources) stays per-resource
     * (the attachTo approve/decline ops + a UI embed), since scoping "resources I own" across morph
     * types is not a generic SQL scope.
     */
    public static function ledgerResources(array $opts = []): void
    {
        $groupPrefix = $opts['groupPrefix'] ?? 'resources';
        $middleware = $opts['middleware'] ?? ['web', 'auth'];

        // The read shape / scope / projection are declared on the attributed Data classes; discovery
        // reflects the #[ParticleResource] + convention scope()/project() into the registry.
        app(AttributedParticleDiscovery::class)->discover([
            AccessGrantData::class,
            ViewRequestData::class,
        ]);

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () {
            Particle::mount('access-grants', 'access-grants')->only(['index']);
            Particle::mount('view-requests', 'view-requests')->only(['index']);
        });
    }

    /** Resolve a validated pending request for this resource, then approve (mint a grant) or decline. */
    private static function decide(Model $resource, Request $request, bool $approve): array
    {
        $data = $request->validate(['request_id' => ['required', 'string']]);

        $viewRequest = ViewRequest::query()
            ->where('id', $data['request_id'])
            ->where('requestable_type', $resource->getMorphClass())
            ->where('requestable_id', (string) $resource->getKey())
            ->firstOrFail();

        $service = app(ViewRequests::class);
        $approve ? $service->approve($viewRequest) : $service->decline($viewRequest);

        return ['data' => ['id' => $viewRequest->id, 'status' => $viewRequest->fresh()->status]];
    }

    private static function resolveUser($id): Model
    {
        $model = config('permission-cascade.user_model') ?: config('auth.providers.users.model');

        if (is_callable($model)) {
            $model = $model();
        }

        return $model::findOrFail($id);
    }
}
