<?php

namespace Splicewire\Beam\Accounts\Contracts;

use Splicewire\Beam\Accounts\Tokens\ActingCredential;

/**
 * A credential-scope resolver that also knows when the acting credential stops authenticating (credential-scope 08,
 * review cs08a). The scope and the lifetime come from ONE resolution of the acting credential, so a revocation racing
 * the request (an archive overwriting the hash, a logout deleting the row) cannot make a later lookup miss and read as
 * "no expiry". beam-accounts' bearer resolver implements it; the machine-token issuer refuses a scoped acting
 * credential's expiry-bearing request when the bound resolver does not.
 */
interface ResolvesActingCredential
{
    /** The acting credential as resolved for this request, or null when it is unscoped (no bearer, unknown, `*`). */
    public function acting(): ?ActingCredential;
}
