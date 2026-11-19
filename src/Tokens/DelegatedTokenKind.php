<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind whose token belongs to a DIFFERENT principal from the actor (ADR 0006 rule 2, second half): a satellite
 * tenant token, a broker token, the engine loopback, a federation resolve grant.
 *
 * Capping such a token at the acting token's abilities would refuse every brokered provisioning (a broker token
 * scoped to `tenant:provision` must still mint a satellite token that reads rules), so the cap is by AUTHORITY
 * instead. The issuer, in order:
 *
 *  1. requires {@see issuanceAbility()} on the acting credential when that credential is scoped;
 *  2. asks {@see boundSubject()} what the kind binds to from its own context (the current tenant, say) and
 *     refuses a request that names any other subject, or names one when the kind resolves none;
 *  3. resolves the token's principal with {@see resolveTarget()}, and refuses a request that names a different one,
 *     and a target that is the actor itself, so the caller never picks the principal or the tenant;
 *  4. checks {@see authorizes()}: the actor's live authority over that resolved principal;
 *  5. mints {@see abilities()}, the kind's declared set. A request that names abilities is refused.
 */
abstract class DelegatedTokenKind extends TokenKind
{
    /** The ability a scoped acting credential must carry to issue this kind. */
    abstract public function issuanceAbility(): string;

    /**
     * The subject this kind binds to, resolved from its OWN context (the current tenant, the grant in hand, the
     * tenant an operator command is bound to), or null when that context is missing.
     *
     * Required, with no default: the issuer resolves the target only from this. A request's subject is a claim
     * checked against it, and is refused (`token_target_mismatch`) when it names another subject or when this
     * returns null; it is never used in its place.
     */
    abstract public function boundSubject(?Authenticatable $actor): mixed;

    /**
     * The principal the token is for, resolved by the kind from the subject, or null when there is none (refused
     * as `token_target_unresolved`). Never the caller's choice.
     */
    abstract public function resolveTarget(?Authenticatable $actor, mixed $subject): ?Model;

    /**
     * Whether `$actor` has live authority, now, over `$target` for `$subject`. A null actor is a console or
     * queue issuance; a kind that allows one says so here.
     */
    abstract public function authorizes(?Authenticatable $actor, Model $target, mixed $subject): bool;

    /**
     * The kind's declared ability set for `$target`. Exactly what its consumer calls: ADR 0006 C8 removes one of
     * these and expects that call to 403.
     *
     * @return list<string>
     */
    abstract public function abilities(Model $target, mixed $subject): array;
}
