<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Carbon\CarbonInterval;
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
 * A kind may also declare a **maximum lifetime** ({@see maxLifetime()}, credential-scope 09): the issuer then refuses a
 * mint, a renewal or a rotation of that kind with no expiry or one past now plus the lifetime.
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

    /**
     * The longest a token of this kind may live, counted from its mint, renewal or rotation; null caps nothing (the
     * default, so a kind that declares none is unchanged). When a kind declares one, {@see MachineTokenIssuer} refuses
     * every issuance, renewal and rotation of it whose expiry is missing ("never") or later than now plus this
     * (`token_lifetime_beyond_kind`), and refuses a zero or negative lifetime as a misconfiguration
     * (`token_kind_lifetime_invalid`) rather than reading it as no cap.
     */
    public function maxLifetime(): ?CarbonInterval
    {
        return null;
    }

    /**
     * A lifetime of `$days` whole days from a host value (config, env), for {@see maxLifetime()}. Anything that is not a
     * positive whole number (null, `0`, `-3`, `'90d'`, `1.5`) becomes a zero lifetime, which the issuer refuses: a
     * misconfigured cap fails closed instead of reading as "no cap".
     */
    public static function lifetimeInDays(mixed $days): CarbonInterval
    {
        $valid = (is_int($days) || (is_string($days) && preg_match('/^[0-9]+$/', $days) === 1)) && (int) $days > 0;

        return CarbonInterval::days($valid ? (int) $days : 0);
    }
}
