<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Authorization\ModelOrOperatorReadPolicy;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Particle\ParticleResource;

/** Preserves invitation model/operator readers and the shipped current-team owner/admin surface. */
class InvitationReadPolicy implements ResourceReadPolicy
{
    public function __construct(private ModelOrOperatorReadPolicy $fallback) {}

    public function inspect(
        ?Authenticatable $actor,
        ParticleResource $resource,
        Request $request,
    ): Response {
        $team = BeamAccounts::currentTeam();

        if ($team instanceof TeamContract && InvitationData::managesTeam($actor, $team)) {
            return Response::allow();
        }

        return $this->fallback->inspect($actor, $resource, $request);
    }
}
