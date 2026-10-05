<?php

namespace Splicewire\Beam\Accounts\Doors;

use Illuminate\Contracts\Auth\Authenticatable;

/** A central user came into existence through `$door` (purchase-walkthrough M10). A host reacts here, e.g. a default role. */
final class AccountCreated
{
    public function __construct(
        public readonly Authenticatable $user,
        public readonly Door $door,
    ) {}
}
