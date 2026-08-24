<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * `GET /me` — the authenticated user's own identity projection, served through the generic particle
 * transport so packages may CONTRIBUTE to it (particle-contribution-seam 16/18).
 *
 * ## Why a controller at all, and why it is only a subject resolver
 *
 * `me` is a **singleton**: there is no `{id}`, because the subject is always the caller. Beam had no
 * singleton resource before this one, and ticket 18 pre-authorized **no new affordance flag** for it —
 * so this rides consumption tier 2 instead (a controller extending {@see ParticleController}, which
 * {@see \Splicewire\Beam\Particle\ParticleResource}'s own docblock names as the sanctioned way to keep
 * a declaration's internals while varying one verb). The override is subject resolution and nothing
 * else: projection, the contribution fold and the response envelope are all inherited, so a contributed
 * slice reaches `/me` through the same {@see \Splicewire\Beam\Particle\Contribution\ContributionProjector}
 * fold point every other resource uses. Nothing about the seam is special-cased here.
 *
 * ## Authorization is the route, deliberately
 *
 * The inherited `show()` calls `$this->authorize('view', $model)` against the User policy. That is the
 * wrong gate for a self-read — `auth:sanctum` on the mount already proves the caller IS the subject,
 * and there is no id to forge (ticket 16 §A6). A policy check here would additionally make `/me`
 * depend on whether a host happens to have registered a `UserPolicy@view`, which most do not.
 *
 * ## The permission-cache flush is load-bearing
 *
 * Lifted verbatim from the host route closure this replaces: the SPA calls `/me` once per tenant
 * navigation and reads its roles/permissions off the result, so a stale spatie permission cache serves
 * the PREVIOUS tenant's grants. Guarded by `class_exists` because beam-accounts does not require
 * spatie/laravel-permission — {@see AuthUserData::identityCore()} reaches for roles the same optional way.
 *
 * @see ResourceContribution  how beam-commerce and beam-embed add their slices to this projection
 */
class MeController extends ParticleController
{
    /** The registry key this controller serves — the resource beam-commerce and beam-embed contribute to. */
    public const KEY = 'me';

    /**
     * Read your profile
     *
     * The authenticated user's identity core — id, name, email, roles, permissions, tenants — plus any
     * sub-projection a contributing package adds (e.g. `commerce`, `embed`).
     *
     * `$id` is accepted with a default so the signature stays compatible with the inherited verb, and
     * ignored: a singleton has no addressable id, and honouring one would be a way to read another
     * user's projection.
     */
    public function show(Request $request, string $id = ''): Responsable
    {
        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $this->respond($this->particleResource($request), $request->user(), $request);
    }

    /**
     * The declaration this controller serves, looked up by key rather than off the route defaults.
     *
     * `/me` is mounted as a plain `Route::get`, not through `Route::particleResource()` — a singleton has
     * no `{id}` and no index — so there is no `_particle` default for the inherited resolver to read.
     * Naming the key here is the override the base class's own docblock invites for exactly this case.
     */
    protected function particleResource(Request $request): ParticleResource
    {
        return $this->registry->get(static::KEY);
    }
}
