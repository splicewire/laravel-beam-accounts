<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Splicewire\Beam\Accounts\Enums\TokenProvenance;

/**
 * A declared kind of machine token (ADR 0006 rule 3, credential-scope 03).
 *
 * Every token row other than a login token is written by {@see MachineTokenIssuer} as one of these. A kind is
 * declared in the `beam.accounts.tokens.kinds` registry ({@see TokenKindRegistry}) under its name, and the issuer
 * records that name on the token's `kind` column. An undeclared kind is refused.
 *
 * A kind is one of two shapes, and only these two:
 *
 *  - {@see SamePrincipalTokenKind}: the token belongs to the acting principal (a personal access token). Under a
 *    scoped acting credential its set is capped at that credential's abilities (ADR 0006 rule 2, first half).
 *  - {@see DelegatedTokenKind}: the token belongs to a different principal (a satellite tenant, a broker, the
 *    engine loopback, a federation grant). The kind resolves that principal, checks the actor's live authority
 *    over it, and requires its issuance ability on the acting credential; the set is the kind's own (rule 2,
 *    second half).
 *
 * Neither shape can produce `*`: the issuer refuses it whatever the kind returns. Only
 * {@see \Splicewire\Beam\Accounts\Auth\AuthTokenFactory} mints `*`, because only a login token stands in for a
 * signed-in session.
 */
abstract class TokenKind
{
    /** The provenance stamped on the row, which drives the Tokens page's type chip. */
    public function provenance(): TokenProvenance
    {
        return TokenProvenance::Api;
    }
}
