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
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Attachable sharing (ADR-0009, tracer 04 generalized): one call gives ANY beam particle
 * resource the full sharing capability over its HasVisibility model — share/unshare (AccessGrant)
 * and the request-access → approve/decline view-request flow. All five verbs are model-agnostic
 * particle operations.
 *
 * ## Authorization (particle-operation-surface 19, RULING 2)
 *
 * All five verbs already declare an `ability:` — `$manage` (default `'update'`) for share/unshare and
 * the two request decisions, `$requestAbility` for `request-access`. There is no ungated op here and
 * there never was, so RULING 2 required no change on this factory. ⚠️ Beam's own prose implies
 * otherwise by counting these ops among an "anchor" population, and it is wrong on both halves: the
 * set is FIVE, not six (the sixth went with the link-sharing primitive gutted 2026-08-28), and none
 * of the five has ever been an anchor at any host. See {@see declareAnchorResource()}.
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

        self::declareAnchorResource($resourceKey, $model);

        Route::middleware($middleware)->prefix($groupPrefix)->group(function () use ($urlKey, $resourceKey, $ops) {
            Particle::ops($urlKey, $resourceKey, $ops);
        });
    }

    /**
     * particle-operation-surface 19, RULING 1 — make sure `$resourceKey` names a DECLARED
     * `ParticleResource`, so the five ops above resolve `{id}` through the registry rather than
     * through `RecordSubject`'s `$operation->model::query()->findOrFail($id)` fallback (which applies
     * none of a resource's `scope`, `routeKey` or `includes`).
     *
     * Registered from the same call that registers the ops, so a host gets it automatically — the
     * whole point being that "this key resolves to that model" is a property of the RESOURCE, stated
     * once, rather than restated on every op.
     *
     * ## At every host that exists today this is a NO-OP, and that is the correct outcome
     *
     * Measured 2026-08-31 from a booted registry probe at every `~/Herd/*` root:
     * `Sharing::attachTo()` has exactly ONE call site in the estate
     * (`~/Herd/audiostud/app/Providers/SongSharingServiceProvider.php`, key `'songs'`), and audiostud
     * already declares `songs` as a real, scoped resource. So the guard below declines and nothing
     * changes. This exists for the host that attaches to a key it has not declared — and beam's own
     * prose has been counting these five ops as live anchors when none of them ever was.
     *
     * ## Two constraints, both load-bearing
     *
     * **Deferred and guarded**, because registering at an exact key that is already taken does NOT
     * throw — it REPLACES, silently, leaving the entry count unchanged. An eager registration would
     * therefore be free to overwrite the host's own `songs` declaration, `scope` gate and all, purely
     * on provider boot order. `booted()` makes the `has()` check read the FINAL registry state, so
     * the host's declaration wins regardless of who booted first.
     *
     * **It opens no affordance.** `$model` is host-supplied, so this package cannot know whether its
     * backing can write, and `BackingResolver::assertAffordancesWithinCapability()` THROWS at
     * registration for an affordance opened past a backing's capability. A closed declaration is also
     * the honest one: an anchor resolves a subject and is never written through — the sharing verbs
     * write through their own handlers, not through a particle write pipeline.
     *
     * @param  class-string<Model>  $model
     */
    protected static function declareAnchorResource(string $resourceKey, string $model): void
    {
        if (! class_exists(ParticleResourceRegistry::class)) {
            return;
        }

        app()->booted(function () use ($resourceKey, $model) {
            $resources = app(ParticleResourceRegistry::class);

            if ($resources->has($resourceKey)) {
                return;
            }

            $resources->register(new ParticleResource(
                key: $resourceKey,
                backing: $model,
                readOnly: true,
                editable: false,
                deletable: false,
                showable: false,
            ));
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
