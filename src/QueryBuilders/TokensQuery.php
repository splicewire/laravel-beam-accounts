<?php

namespace Splicewire\Beam\Accounts\QueryBuilders;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

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
}
