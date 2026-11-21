<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Carbon\CarbonInterface;

/**
 * A scoped acting credential as resolved once for the request: its ability list and when it stops authenticating
 * (null: it never expires). Held together so the lifetime cap reads the same row the scope came from.
 */
final class ActingCredential
{
    /** @param  list<string>  $scope */
    public function __construct(
        public readonly array $scope,
        public readonly ?CarbonInterface $expiresAt,
    ) {}
}
