<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\AuthUserExtrasContributor;

/**
 * The standalone / null-host default for the auth-projection VALUE seam. Contributes no
 * extra fields, so a beam-accounts site with no host layered on top projects the pure
 * identity core — the host fields are ABSENT, not empty (extension-seam asset 07, req 4).
 */
class NullAuthUserExtras implements AuthUserExtrasContributor
{
    public function contribute(Authenticatable $user): array
    {
        return [];
    }
}
