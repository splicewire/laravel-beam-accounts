<?php

namespace Splicewire\Beam\Accounts\QueryBuilders;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Membership;

/**
 * The load-bearing row-level scope for the account Users resource.
 *
 * Users live in ONE table shared by every principal — the widest blast radius in the package — so
 * an unscoped query leaks the entire identity roster to any authenticated seat. This is the
 * {@see TokensQuery} discipline applied to the identity table: the SAME closure serves the list and
 * the per-record read, so a detail request can never resolve a user the list would have hidden.
 *
 * DOMAIN-NEUTRAL default ({@see self::scopeToSharedTeams()}): the acting principal sees themselves
 * plus every user they share a team with — the same boundary the `members` resource already draws,
 * widened from one team to all of the actor's teams. A CENTRAL Root principal
 * ({@see BeamAccounts::isRoot()}) sees the whole table, since support work spans tenants the operator
 * is not a member of. A null actor yields an empty result (`whereRaw('1 = 0')`), never the whole
 * table — the fail-safe for an unauthenticated caller.
 *
 * The membership read goes through the package's own {@see Membership} reference model (the
 * `beam_memberships` seat rows). A host whose seats live elsewhere — a tenant pivot, a directory,
 * a role-derived roster — binds `beam.accounts.users.scope`, an
 * `(Builder, ?Authenticatable): Builder` seam the resource applies in place of this default.
 */
class UsersQuery
{
    /**
     * Scope a user query to the principals the given actor may see: themselves, plus everyone
     * holding a seat on any team the actor holds a seat on. Root sees all; a null actor sees none.
     */
    public static function scopeToSharedTeams(Builder $query, ?Authenticatable $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if (BeamAccounts::isRoot($user)) {
            return $query;
        }

        $key = $query->getModel()->getQualifiedKeyName();
        $membershipTable = (new Membership)->getTable();

        // The actor's own teams, as a subquery rather than a materialized id list — a principal on
        // many teams must not turn one list request into an unbounded IN(...) binding.
        $actorTeams = Membership::query()
            ->getQuery()
            ->from($membershipTable)
            ->select('team_id')
            ->where('user_id', $user->getKey());

        return $query->where(function (Builder $scoped) use ($key, $user, $membershipTable, $actorTeams): void {
            // Always yourself — a principal with no seat at all still resolves their own record.
            $scoped
                ->where($key, $user->getKey())
                ->orWhereIn($key, function ($peers) use ($membershipTable, $actorTeams): void {
                    $peers
                        ->from($membershipTable)
                        ->select('user_id')
                        ->whereIn('team_id', $actorTeams);
                });
        });
    }
}
