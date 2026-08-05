<?php

namespace Splicewire\Beam\Accounts\Sharing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Models\ViewRequest;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * Attachable sharing (ADR-0009, tracer 04+06 generalized): one call gives ANY beam particle
 * resource the full sharing capability over its HasVisibility model — share/unshare (AccessGrant),
 * mint a link (ShareLink, scope `{morphAlias}:{id}`), and the request-access → approve/decline
 * view-request flow. All six verbs are model-agnostic particle operations; only what a share
 * link *renders to* stays host-specific (the host registers a {@see ShareLinkScopes} handler for
 * its morph alias).
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
        $morphAlias = (new $model)->getMorphClass();

        // Any authenticated non-owner may request access (unless the host supplied its own ability).
        if (! isset($opts['requestAbility'])) {
            Gate::define($requestAbility, fn ($user, Model $resource) => ($resource->user_id ?? null) != $user->getKey());
        }

        $registry = app(ParticleOperationRegistry::class);

        $registry->register(new ParticleOperation(
            resource: $resourceKey, name: 'share', kind: OperationKind::Write, model: $model, ability: $manage,
            handle: function (Model $resource, Request $request) {
                $data = $request->validate([
                    'recipient_id' => ['required'],
                    'ability' => ['nullable', 'in:view,manage'],
                ]);
                app(AccessGrants::class)->share($resource, self::resolveUser($data['recipient_id']), $data['ability'] ?? AccessGrant::ABILITY_VIEW);

                return ['data' => ['id' => $resource->getKey(), 'shared_with' => $data['recipient_id']]];
            },
        ));

        $registry->register(new ParticleOperation(
            resource: $resourceKey, name: 'unshare', kind: OperationKind::Write, model: $model, ability: $manage,
            handle: function (Model $resource, Request $request) {
                $data = $request->validate([
                    'recipient_id' => ['required'],
                    'ability' => ['nullable', 'in:view,manage'],
                ]);
                $removed = app(AccessGrants::class)->revoke($resource, self::resolveUser($data['recipient_id']), $data['ability'] ?? null);

                return ['data' => ['id' => $resource->getKey(), 'revoked' => $removed]];
            },
        ));

        $registry->register(new ParticleOperation(
            resource: $resourceKey, name: 'share-link', kind: OperationKind::Write, model: $model, ability: $manage,
            handle: function (Model $resource, Request $request) use ($morphAlias) {
                $data = $request->validate(['max_uses' => ['nullable', 'integer', 'min:1']]);
                $link = app(ShareLinks::class)->create(
                    $request->user(),
                    "{$morphAlias}:{$resource->getKey()}",
                    maxUses: $data['max_uses'] ?? null,
                );

                return ['data' => ['token' => $link->token, 'url' => route('beam.share-link.resolve', $link->token)]];
            },
        ));

        $registry->register(new ParticleOperation(
            resource: $resourceKey, name: 'request-access', kind: OperationKind::Write, model: $model, ability: $requestAbility,
            handle: function (Model $resource, Request $request, $actor) {
                $viewRequest = app(ViewRequests::class)->request($resource, $actor);

                return ['data' => ['id' => $viewRequest->id, 'status' => $viewRequest->status]];
            },
        ));

        foreach (['approve' => true, 'decline' => false] as $verb => $approve) {
            $registry->register(new ParticleOperation(
                resource: $resourceKey, name: "{$verb}-request", kind: OperationKind::Write, model: $model, ability: $manage,
                handle: fn (Model $resource, Request $request) => self::decide($resource, $request, $approve),
            ));
        }

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () use ($urlKey, $resourceKey) {
            foreach (['share', 'unshare', 'share-link', 'request-access', 'approve-request', 'decline-request'] as $op) {
                Route::particleOp($urlKey, $resourceKey, $op);
            }
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
