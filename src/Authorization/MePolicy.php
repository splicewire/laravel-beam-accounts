<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Particle\ParticleResource;

/** Admits an authenticated actor to the `me` resource; its declared scope confines the row to that actor. */
class MePolicy implements ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        return $actor === null
            ? Response::deny('Authentication required.')
            : Response::allow();
    }
}
