<?php

namespace Splicewire\Beam\Accounts\QueryBuilders;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The load-bearing row-level scope for the account Tokens resource (Frame OS ticket 20).
 *
 * Personal access tokens live in ONE table shared by every user, so an unscoped query would
 * leak (list) — and, through the generic Frame revoke-by-id, DELETE — every user's tokens.
 * {@see self::scopeToOwner()} reproduces Sanctum's `HasApiTokens::tokens()` morph constraint:
 * the acting user's OWN tokens (`tokenable_type`/`tokenable_id` = the user's morph alias/key).
 * A null actor yields an empty result (`whereRaw('1 = 0')`), never the whole table — the
 * fail-safe for an unauthenticated caller.
 *
 * DOMAIN-NEUTRAL: this scopes to the AUTHENTICATED principal, no tenant/central notion. A host
 * whose token owner is resolved differently (e.g. tower's cross-guard central User) binds its
 * own scope via `beam.accounts.tokens.scope` — an `(Builder, ?Authenticatable): Builder` seam
 * the resource applies in place of this default.
 */
class TokensQuery
{
    /**
     * Scope a PAT query to the tokens owned by the given user, via the Sanctum morph constraint
     * (morphMap-safe — `tokenable_type` is the user model's morph ALIAS via `getMorphClass()`,
     * not the raw FQCN). Shared by the list query and the resource's revoke scope so list-scope
     * and revoke-scope are provably the same boundary.
     */
    public static function scopeToOwner(Builder $query, ?Authenticatable $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey());
    }

    /**
     * The SAME ownership question as {@see self::scopeToOwner()}, asked of one already-loaded token
     * instead of a query — what a policy can ask, since a policy is handed the model.
     *
     * It lives here, adjacent to the scope, precisely so the two cannot drift: they are one rule
     * ("`tokenable` is this principal") written twice only because a WHERE clause and a predicate are
     * different grammars. {@see \Splicewire\Beam\Accounts\Authorization\TokenPolicy} is the caller.
     *
     * Keys compare as strings for the same reason the cascade's own `ownsDirectly()` does: the estate
     * spans uuid-keyed and bigint-keyed hosts, and `tokenable_id` reads back typed by the driver.
     */
    public static function isOwnedBy(Model $token, ?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $token->getAttribute('tokenable_type') === $user->getMorphClass()
            && (string) $token->getAttribute('tokenable_id') === (string) $user->getAuthIdentifier();
    }
}
