<?php

namespace Splicewire\Beam\Accounts\Doors;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The host's declared door policy (purchase-walkthrough M10), as the login pages read it: whether "Create account"
 * renders, which OAuth providers sign in, whether an operator may add people.
 */
#[TypeScript]
final class AccountDoorsData extends Data
{
    /** @param  array{providers: list<string>, create: string, domains: list<string>}  $oauth */
    public function __construct(
        public readonly string $registration,
        public readonly array $oauth,
        public readonly bool $operator,
    ) {}
}
