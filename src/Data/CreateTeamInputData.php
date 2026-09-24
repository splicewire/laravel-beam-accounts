<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Ops\CreateTeam;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `input:` shape of the `create` operation on `teams` ({@see CreateTeam}) — validated by
 * `ParticleOperationController` before the handler runs, so a blank or overlong name is a 422 (or, from
 * the Inertia form, a redirect back carrying the `name` error) and never reaches the provisioner.
 *
 * Only the name: the creator, the Owner role and the current-team switch are facts about the ACTOR and
 * the operation, not caller payload a form could forge.
 */
#[TypeScript]
class CreateTeamInputData extends BeamData
{
    public function __construct(
        #[Required, Max(255)]
        public string $name,
    ) {}
}
