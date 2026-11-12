<?php

namespace Splicewire\Beam\Accounts\Analytics;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Schemastud\Frame\Authorization\ResourceAuthorizer;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\NavManifest;
use Schemastud\Frame\Registry\RouteContextEntry;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ScopedIndexQuery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The indexes the Audience area counts and links to: this package's own `members`, `invitations`, `users` and `teams`,
 * read and linked through the seams their own lists use, so a figure cannot disagree with its index and no link is dead.
 *
 * A **count** goes through frame's reach check ({@see ResourceAuthorizer::authorizeAccess()}, as the list route asks),
 * then the index's own read:
 *  - a model-backed index: the **scoped count path**, {@see ScopedIndexQuery::forDefinition()} with eager loads and
 *    ordering dropped (what `forAggregate()` is), which applies the resource's read guard, its owner scope and its
 *    `scope()` exactly as the list does, then counted;
 *  - `members`, a stream: its own source's records, every cursor page, after its read guard.
 *
 * Each is read with a request of its own that carries this reader and the route serving this request (so its realm),
 * and no `filter[…]`: the area's own request must not leak its query into another index. A refusal anywhere answers
 * null, and the area then draws no figure for it.
 *
 * A **link** is the index's list leaf by its own route name ({@see ListRouteName}), only where frame's route context for
 * this realm binds that name (the route exists) AND the reader's projected rail carries a leaf of that name (the rail
 * admits it), both read from {@see NavManifest}, the block `/frame/manifest` serves. A host that binds no
 * navigation contributor draws no rail, so no link.
 */
final class AudienceDestinations
{
    public function __construct(
        private readonly ResourceRegistry $frame,
        private readonly ParticleResourceRegistry $particles,
        private readonly ResourceAuthorizer $authorizer,
        private readonly ScopedIndexQuery $query,
        private readonly NavManifest $nav,
    ) {}

    /** How many rows the model-backed index `$key` lists for this reader here, or null when it may not read it. */
    public function count(string $key): ?int
    {
        if (! $this->frame->has($key)) {
            return null;
        }

        $this->requireViewer();

        try {
            $definition = $this->frame->get($key);
            $this->authorizer->authorizeAccess($definition);
            if (! $this->query->queryable($definition)) {
                return null;
            }

            return (int) $this->query->forDefinition($definition, $this->read())->setEagerLoads([])->reorder()->count();
        } catch (AuthorizationException|HttpExceptionInterface) {
            return null;
        }
    }

    /**
     * Every row the streamed `members` index lists for this reader, across its cursor pages, or null when it may not read it.
     *
     * @return list<MembershipData>|null
     */
    public function members(): ?array
    {
        if (! $this->frame->has('members') || ! $this->particles->has('members')) {
            return null;
        }

        $this->requireViewer();

        try {
            $this->authorizer->authorizeAccess($this->frame->get('members'));
            $resource = $this->particles->get('members');
            ResourceReadGuard::forApp()->authorizeReadContext($resource, $this->read());
        } catch (AuthorizationException|HttpExceptionInterface) {
            return null;
        }

        $backing = $resource->backing();
        if (! $backing instanceof StreamsRecords) {
            return null;
        }

        $rows = [];
        $cursor = null;
        do {
            $page = $backing->records([], $cursor, 100);
            foreach ($page->items() as $row) {
                $rows[] = $row;
            }
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null);

        return $rows;
    }

    /** The relative href of `$key`'s list for this reader in this realm, or null when the rail has no live leaf for it. */
    public function href(string $key): ?string
    {
        if (! $this->frame->has($key)) {
            return null;
        }

        $routeName = ListRouteName::of($this->frame->get($key));

        try {
            $block = $this->nav->forRealm(NavManifest::realmFor(request()));
        } catch (Throwable) {
            return null; // a realm the host cannot project binds no leaf
        }

        $bound = array_filter($block['routeContext'] ?? [], fn ($entry): bool => $entry instanceof RouteContextEntry && $entry->routeName === $routeName);
        if ($bound === []) {
            return null;
        }

        return $this->railHref($block['nav']['items'] ?? [], $routeName);
    }

    /** The href of the rail leaf named `$routeName`, at any depth of the reader's projected nav, or null. */
    private function railHref(array $nodes, string $routeName): ?string
    {
        foreach ($nodes as $node) {
            $node = is_object($node) && method_exists($node, 'toArray') ? $node->toArray() : (array) $node;
            if (($node['routeName'] ?? null) === $routeName && is_string($node['href'] ?? null)) {
                return $node['href'];
            }
            $found = $this->railHref((array) ($node['children'] ?? []), $routeName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** A request of its own for one index read: this reader, the route serving this request, no query. */
    /**
     * analytics-area 19 (charter shape, 2026-11-12): every count here is a viewer's own index read, so with no signed-in viewer
     * there is no answer to give. Say so BEFORE the authorizer, whose refusal is caught to null below and would read as a card
     * with nothing to show: an AuthorizationException, which the doctor's dashboard-tier audit classes as UNKNOWN ("verify with
     * a permitted signed-in actor"). A signed-in viewer an index refuses still reads null.
     *
     * @throws AuthorizationException
     */
    private function requireViewer(): void
    {
        if (request()->user() === null) {
            throw new AuthorizationException('The Audience area reads each index as its viewer; with no signed-in viewer it cannot be read.');
        }
    }

    private function read(): Request
    {
        $current = request();
        $read = Request::create('/', 'GET');
        $read->setUserResolver(fn () => $current->user());
        $read->setRouteResolver(fn () => $current->route());

        return $read;
    }
}
