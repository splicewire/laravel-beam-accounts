<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountShellData;

/**
 * An opt-in {@see AccountShellProvider} — reads nothing, returns null, so the shell renders empty. Bind
 * it to hide the account shell's profile and plan blocks entirely. The package default is
 * {@see IdentityAccountShellProvider} (identity only, no plan).
 */
class NullAccountShellProvider implements AccountShellProvider
{
    public function shellFor(?Authenticatable $user): ?AccountShellData
    {
        return null;
    }
}
