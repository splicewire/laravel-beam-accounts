<?php

namespace Splicewire\Beam\Accounts\Frame\Sources;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Schemastud\Frame\Contracts\ResolvedUnionItem;
use Schemastud\Frame\Contracts\UnionQuery;
use Schemastud\Frame\Contracts\UnionSource;
use Splicewire\Beam\Accounts\Concerns\HasMembers;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Membership;

/**
 * The team-members union source (Frame OS ticket 20 — promoted from tower's
 * `Splicewire\Tower\Frame\Sources\MembershipSource`, now domain-neutral in beam-accounts).
 *
 * Source-backed, not model-backed — and the reason is DOMAIN-NEUTRALITY, not the absence of a
 * model. The package does ship {@see Membership}, a real Eloquent model on `beam_memberships`;
 * `Team::memberships()` uses it. What it cannot do is stand for *every* host's membership: a host
 * whose team lives on a foreign pivot ({@see HasMembers} — the app's `Tenant` over `tenant_users`,
 * uuid keys, `removed_at`) has no `Membership` rows at all. A `#[ParticleResource]` names ONE
 * `model:`, so a model-backed declaration would bind this resource to beam's own table and break
 * exactly the hosts the trait exists to serve. Hence the union-source route: it reads whatever
 * pivot the host's `members()` relation exposes and adapts it to Frame's {@see UnionSource}
 * contract, riding beam-core's generic `source:` widening. LIST-ONLY by construction.
 *
 * DOMAIN-NEUTRAL: the team is {@see BeamAccounts::currentTeam()} (a host binds its own scope resolver).
 * beam resolves the instance off the container at request time, so no explicit binding is needed.
 */
class MembershipSource implements UnionSource
{
    public function index(UnionQuery $query): CursorPaginatorContract
    {
        $stream = $this->stream();

        $cursor = $query->cursor !== null ? Cursor::fromEncoded($query->cursor) : null;

        if ($cursor !== null) {
            $lastId = $cursor->parameter('id');
            $offset = $stream->search(fn (MembershipData $item) => $item->id === $lastId);
            $stream = $offset === false ? $stream : $stream->slice($offset + 1)->values();
        }

        $slice = $stream->take($query->perPage + 1)->values();

        return new CursorPaginator(
            $slice,
            $query->perPage,
            $cursor,
            ['parameters' => ['id']],
        );
    }

    public function find(string $source, string $id): ?ResolvedUnionItem
    {
        $item = $this->stream()->first(fn (MembershipData $member) => $member->id === $id);

        if ($item === null) {
            return null;
        }

        return new ResolvedUnionItem(item: $item, schemaRef: null);
    }

    /**
     * The current team's members as a projected stream, one {@see MembershipData} per seat.
     *
     * @return Collection<int, MembershipData>
     */
    protected function stream(): Collection
    {
        $team = BeamAccounts::currentTeam();

        if ($team === null || ! method_exists($team, 'members')) {
            return collect();
        }

        // `members()` has two legal return shapes across the hosts this source serves, and calling
        // `->get()` unconditionally is wrong for one of them: beam's own {@see Models\Team::members()}
        // returns a `BelongsToMany` (needs `->get()`), while {@see HasMembers::members()} — the trait a
        // foreign-pivot host uses — already returns a `get()`-ed Collection. `Collection::get()`
        // requires a `$key`, so the unconditional call threw `ArgumentCountError` on every members
        // list for a `HasMembers` host, and the `method_exists()` guard above passes for both, which
        // is what hid it.
        $members = $team->members();

        if ($members instanceof Relation) {
            $members = $members->get();
        }

        return $members
            ->map(fn (Model $user) => new MembershipData(
                id: (string) $user->getKey(),
                name: $user->name,
                email: $user->email,
                role: $user->pivot->role,
                joinedAt: $this->pivotTimestamp($user->pivot->created_at ?? null),
            ))
            ->values();
    }

    /** Normalise an uncast pivot timestamp (Carbon | string | null) to a nullable ISO-8601 string. */
    protected function pivotTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : (string) $value;
    }
}
