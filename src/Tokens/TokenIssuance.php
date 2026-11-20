<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * One request to {@see MachineTokenIssuer}: who asks, what to call the token, and what the request states.
 *
 * What a request STATES is never what it gets. `abilities` is a same-principal kind's input (its kind decides the
 * set); `subject` and `principal` are claims a delegated kind checks against what it resolves itself, so a caller
 * that names a different principal or tenant is refused rather than obeyed (ADR 0006 C6).
 *
 * `deterministicId` and `deterministicPlaintext` together ask for a reset-surviving key with a fixed bearer
 * (`{id}|{plaintext}`), the storage need the retired `Keys\DeterministicToken` served before credential-scope 04.
 */
final class TokenIssuance
{
    /**
     * @param  list<string>|null  $abilities
     */
    public function __construct(
        public readonly ?Authenticatable $actor,
        public readonly string $name,
        public readonly ?array $abilities = null,
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly mixed $subject = null,
        public readonly ?Model $principal = null,
        public readonly ?string $deterministicId = null,
        public readonly ?string $deterministicPlaintext = null,
    ) {}
}
