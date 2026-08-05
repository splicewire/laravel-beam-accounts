<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountShellData;

/**
 * The default {@see AccountShellProvider} — reads nothing, returns null. A host that never binds its
 * own provider gets this, so consuming the shell prop is safe OOTB (the shell simply renders empty).
 */
class NullAccountShellProvider implements AccountShellProvider
{
    public function shellFor(?Authenticatable $user): ?AccountShellData
    {
        return null;
    }
}
