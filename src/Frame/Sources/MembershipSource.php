<?php

namespace Splicewire\Beam\Accounts\Frame\Sources;

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Schemastud\Frame\Contracts\ResolvedUnionItem;
use Schemastud\Frame\Contracts\UnionQuery;
use Schemastud\Frame\Contracts\UnionSource;

use function Splicewire\Beam\Accounts\accountCurrentTeam;

use Splicewire\Beam\Accounts\Data\Frame\MembershipResourceData;

/**
 * The team-members union source (Frame OS ticket 20 — promoted from tower's
 * `Splicewire\Tower\Frame\Sources\MembershipSource`, now domain-neutral in beam-accounts).
 *
 * Members are a MODEL-LESS resource: the list is the current team's membership PIVOT (role +
 * joinedAt), not a plain user query. A member row carries pivot columns a `Data::from(user)`
 * cannot reach, and "who is a member" is a per-team pivot scope, so this can neither be a
 * model-backed `#[ParticleResource]` nor a scoped user query — it adapts the pivot read to
 * Frame's {@see UnionSource} contract and rides beam-core's generic `source:` widening. LIST-ONLY
 * by construction.
 *
 * DOMAIN-NEUTRAL: the team is {@see accountCurrentTeam()} (a host binds its own scope resolver).
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
            $offset = $stream->search(fn (MembershipResourceData $item) => $item->id === $lastId);
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
        $item = $this->stream()->first(fn (MembershipResourceData $member) => $member->id === $id);

        if ($item === null) {
            return null;
        }

        return new ResolvedUnionItem(item: $item, schemaRef: null);
    }

    /**
     * The current team's members as a projected stream, one {@see MembershipResourceData} per seat.
     *
     * @return Collection<int, MembershipResourceData>
     */
    protected function stream(): Collection
    {
        $team = accountCurrentTeam();

        if ($team === null || ! method_exists($team, 'members')) {
            return collect();
        }

        return $team->members()
            ->get()
            ->map(fn (Model $user) => new MembershipResourceData(
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
