<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Ops\RedeemInvitation;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `output:` shape of the `redeem` operation on `invitations` ({@see RedeemInvitation}) —
 * the seat the invitee now holds. A JSON caller reads it; the Inertia form is redirected instead, and
 * the new team is already the caller's current team by the time either answer is sent.
 */
#[TypeScript]
class InvitationAcceptedData extends BeamData
{
    public function __construct(
        public string $teamId,
        public string $teamName,
        public string $role,
    ) {}
}
