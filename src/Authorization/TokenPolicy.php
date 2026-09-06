<?php

namespace Splicewire\Beam\Accounts\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\QueryBuilders\TokensQuery;

/**
 * The authorization for personal access tokens — the model behind the `tokens` Frame resource
 * ({@see TokenData}), and the reason a team owner can revoke an API key again after
 * `Schemastud\Frame\Authorization\ResourceAuthorizer` began refusing every write against a model
 * with no policy.
 *
 * ## One rule: a token belongs to the principal it was minted for
 *
 * `view`/`update`/`delete` are answered by {@see TokensQuery::isOwnedBy()} and nothing else —
 * deliberately the SAME predicate the resource's row scope ({@see TokensQuery::scopeToOwner()})
 * applies, so the gate and the scope cannot answer differently. `viewAny` and `create` have no row
 * to ask about and are answered for any authenticated principal.
 *
 * ## Why NOT `#[UseCascadePolicy]`, and why not a `BaseModelPolicy` subclass
 *
 * Both were tried and both are wrong here, in ways worth recording because they look right:
 *
 *  - The **attribute** expresses only UNCONDITIONAL overrides (`create: true`, `update: false`).
 *    The rule this model needs is conditional on the ROW. `BaseModelPolicy`'s existing conditional
 *    arm for exactly that — the inherent morph-owner steward — keys off `HasMorphUser`, i.e.
 *    `user_type`/`user_id`; a Sanctum PAT owns `tokenable_type`/`tokenable_id`, so the trait does
 *    not fit and adding it would claim columns this table does not have.
 *  - **Subclassing `BaseModelPolicy`** and falling back to the cascade for what the own-arm denies
 *    is worse than it looks. {@see RolePermissions} derives its model set from `Gate::policies()`
 *    and crosses it with UNIFORM per-role abilities, so every policed model gets `view` at MEMBER
 *    tier. On a table shared by every user in the estate that is not a tier — it is a class-level
 *    `view` on *everyone's* tokens. Measured 2026-09-05: with the subclass in place, a member was
 *    allowed `view` on a peer's token row, through `canCascade`'s first rung. The uniform tiering is
 *    right for an ordinary resource and wrong for this one, and the way to say so is to leave the
 *    derived set rather than to fight it.
 *
 * So this is a plain policy class, and `PersonalAccessToken` is deliberately ABSENT from
 * `RolePermissions::policedModels()`. That absence is asserted, not incidental — see
 * `ResourceModelPoliciesTest` — because the alternative reading ("nobody got round to it") is the
 * exact ambiguity the fail-closed change existed to remove.
 *
 * ## The product decision: a member MAY hold and revoke their own API tokens
 *
 * Self-service, for every seat, not just owners:
 *
 *  - A PAT is a CREDENTIAL, not an authority. Minting one grants nothing the principal does not
 *    already hold: {@see TokenAbilitiesScopeResolver} intersects the token's `abilities` with the
 *    live permission cascade, and that intersection can only ever SUBTRACT ("the token half can only
 *    ever subtract; the cascade owns the authority ceiling"). Self-service escalates nothing.
 *  - The tiered alternative — member = `view` only — is the worst of the three postures: a member
 *    can SEE a leaked credential and not revoke it, routing incident response through an admin.
 *  - Revoking your own credential is not an act anyone needs protecting from.
 *
 * Contrast {@see \Splicewire\Beam\Accounts\Models\Invitation}, which takes the plain tiered
 * `#[UseCascadePolicy]`: an invitation changes WHO CAN REACH THE TENANT, so it stays owner/admin.
 *
 * ⚠️ **No admin-revokes-a-member's-token arm, on purpose.** It would have to be a class-level token
 * that the uniform tiering hands out, and it would be inert anyway: `TokenData::scope()` resolves the
 * revoke subject through the owner scope, so an admin's request 404s before authorization matters. A
 * host that genuinely wants operator revocation widens `beam.accounts.tokens.scope` AND binds its own
 * policy — {@see \Splicewire\Beam\Accounts\Concerns\WiresAuthorization} registers this one only when
 * the model has none, so the host's wins. Two seams, one decision, neither half working alone.
 *
 * ⚠️ `create` is presently unreachable at every host in the estate and is defined anyway. `tokens` is
 * declared `readOnly: true, deletable: true`, so Frame's socket answers 405 to `store`, and the
 * reveal-once mint is a host REST survivor by design (see {@see TokenData}'s docblock) — measured at
 * `~/Herd/beam` 2026-09-05, `route:list` carries no token-minting route at all. Defining it here is
 * what makes the answer already correct for the host that adds one, instead of leaving that host to
 * discover its mint route is gated by nothing.
 */
class TokenPolicy
{
    /**
     * Listing tokens is listing YOUR tokens: {@see TokenData::scope()} pins the result set to the
     * caller's own rows on the way in, so there is no wider list for this to open up.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    public function view(?Authenticatable $user, Model $token): bool
    {
        return $this->owns($user, $token);
    }

    /** Self-service mint — see the class docblock. */
    public function create(?Authenticatable $user): bool
    {
        return $user !== null;
    }

    /**
     * Renaming a token. Unreachable today (`readOnly: true` ⇒ the socket answers 405 to `update`)
     * and defined anyway, so a host that widens `editable` inherits the ownership rule rather than
     * falling through to a policy that has no method and denies for an unrelated reason.
     */
    public function update(?Authenticatable $user, Model $token): bool
    {
        return $this->owns($user, $token);
    }

    /** Revoke. The one write verb the `tokens` resource actually exposes. */
    public function delete(?Authenticatable $user, Model $token): bool
    {
        return $this->owns($user, $token);
    }

    /**
     * Ownership, with the one concession an own-only policy has to make to a CLASS-LEVEL probe.
     *
     * `Schemastud\Frame\Authorization\ResourceAuthorizer` asks the instance abilities TWICE. With a
     * record id the answer is AUTHORITATIVE — it is what returns 403. With no id, to decide whether an
     * affordance is worth rendering, it probes against a fresh unsaved model, and that answer is
     * ADVISORY: *"with no instance to police, an instance-dependent policy is probed against a fresh
     * unsaved model, which can only be a class-level approximation."*
     *
     * An own-only rule answers FALSE to that probe by construction — a `new PersonalAccessToken` has
     * no `tokenable`, so nobody owns it — and the console then renders ZERO revoke buttons for every
     * actor. Measured at `~/Herd/beam` 2026-09-05 as the team OWNER: `can.delete` came back `false`
     * on the `tokens` block while `DELETE /frame/resources/tokens/records/1` returned **204**. The
     * gate and the affordance disagreed, which is the exact failure the capability map exists to end.
     *
     * So an UNSAVED instance is read as the class-level question — *may you revoke tokens at all?* —
     * and the answer is yes for any authenticated principal, because everyone may revoke their own.
     * The narrowing to WHICH token then happens on the authoritative call, which always carries a
     * persisted row.
     *
     * ⚠️ This does NOT widen the endpoint. `ResourceAuthorizer::subject()` falls back to a fresh
     * instance only when the id resolves to nothing, and `TokenData::scope()`'s owner-scoped
     * `findOrFail` answers that request with a 404 either way. The one behaviour change is 403 → 404
     * for a token id that does not exist, which is the better of the two: it stops the gate from
     * reporting existence.
     */
    protected function owns(?Authenticatable $user, Model $token): bool
    {
        if (! $token->exists) {
            return $user !== null;
        }

        return TokensQuery::isOwnedBy($token, $user);
    }
}
