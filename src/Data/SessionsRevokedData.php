<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Http\Controllers\Account\ApiTokenController;
use Splicewire\Beam\Data\BeamData;

/**
 * Outcome of the "log out everywhere else" sweep ({@see ApiTokenController::destroyOtherSessions()})
 * — how many OTHER session tokens the sweep revoked, carried in the standard envelope's `data` slot
 * while the human copy rides `message`.
 *
 * A Data class rather than a bare top-level `{revoked}` key so the route's declared response is
 * honest (the small-result idiom). Promoted from `Splicewire\Tower\Data\SessionsRevokedData` with
 * the account-tier surface it belongs to; tower's own copy is untouched, because tower mounts its
 * own controller against its own cross-guard central-user model and this promotion does not migrate
 * that host.
 */
#[TypeScript]
class SessionsRevokedData extends BeamData
{
    public function __construct(
        public int $revoked,
    ) {}
}
