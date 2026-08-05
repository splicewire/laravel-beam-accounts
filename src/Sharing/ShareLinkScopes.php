<?php

namespace Splicewire\Beam\Accounts\Sharing;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Models\ShareLink;

/**
 * The host seam for the generic `/s/{token}` resolver (ADR-0009, tracer 06). A {@see ShareLink}
 * carries an opaque `scope` string (`{prefix}:{ref}`, e.g. `composition:{uuid}`). beam-accounts
 * owns the reusable front door — validate the token, honor expiry/revocation/use-cap, count a use
 * — and dispatches the *meaning* of the scope to a handler the HOST registers here. The host's
 * handler returns whatever response the target warrants (an Inertia page, a redirect, …); this
 * package never learns what a `composition` is.
 *
 * Bound as a singleton so a host registers handlers (in its provider boot) on the same instance
 * the resolver reads.
 */
class ShareLinkScopes
{
    /** @var array<string, Closure> prefix => fn(ShareLink, string, Request): mixed */
    protected array $handlers = [];

    /** Register the handler for a scope prefix (e.g. 'composition'). Last registration wins. */
    public function handle(string $prefix, Closure $handler): void
    {
        $this->handlers[$prefix] = $handler;
    }

    public function hasHandler(string $prefix): bool
    {
        return isset($this->handlers[$prefix]);
    }

    /**
     * Dispatch a validated link to its host handler and return that handler's response. A scope
     * with no registered handler is a 404 — an unknown target, not a server error.
     */
    public function resolve(ShareLink $link, Request $request): mixed
    {
        [$prefix, $ref] = array_pad(explode(':', $link->scope, 2), 2, '');

        $handler = $this->handlers[$prefix] ?? null;

        abort_if($handler === null, 404);

        return $handler($link, $ref, $request);
    }
}
