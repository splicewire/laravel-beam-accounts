<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Database\Eloquent\Model;

/** A token {@see MachineTokenIssuer} wrote: the row and its bearer, which is shown once and never stored. */
final class IssuedToken
{
    public function __construct(
        public readonly Model $token,
        public readonly string $plainTextToken,
    ) {}
}
