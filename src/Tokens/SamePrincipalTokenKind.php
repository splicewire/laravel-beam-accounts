<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A kind whose token belongs to the principal that asks for it: a personal access token.
 *
 * The issuer takes the principal from {@see TokenIssuance::$actor}, never from the request, and caps the set this
 * kind returns at the acting credential's abilities when that credential is scoped (ADR 0006 C6, first sentence).
 * A session or a `*` login token is not scoped, so the kind's own answer stands.
 */
abstract class SamePrincipalTokenKind extends TokenKind
{
    /**
     * The ability set to mint for `$principal`, from what the request asked for (null when it asked for nothing).
     *
     * Refuse with a coded error rather than trim: a request for something the principal may not have is a
     * mistake the caller should see.
     *
     * @param  list<string>|null  $requested
     * @return list<string>
     */
    abstract public function abilities(Authenticatable $principal, ?array $requested): array;
}
