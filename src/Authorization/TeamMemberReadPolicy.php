<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Particle\ParticleResource;

/** Admits the current team's active members to its source-backed roster. */
class TeamMemberReadPolicy implements ResourceReadPolicy
{
    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        $team = BeamAccounts::currentTeam();

        return $actor !== null
            && $team instanceof TeamContract
            && $team->hasMember($actor)
                ? Response::allow()
                : Response::deny("Reading [{$resource->key}] requires membership in the current team.");
    }
}
