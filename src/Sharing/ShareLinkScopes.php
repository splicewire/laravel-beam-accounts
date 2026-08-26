<?php

namespace Splicewire\Beam\Accounts\Sharing;

use Closure;
use Illuminate\Http\Request;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
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
 *
 * ## On the popcorn kernel (registry-kernel ticket 38)
 *
 * The prefix map is a {@see BasicRegistry} held as a FIELD — composition, never a base class — so
 * duplicate policy, registration order and the authorization filter are the kernel's one
 * implementation rather than a second one drifting beside it. The keys are supplied by a
 * registrant at boot from a declared vocabulary (`song` in audiostud, `composition` in the
 * splicewire app), which is what makes this a registry rather than a per-request cache.
 *
 * ⚠️ **`resolve()` answers two questions, and the split is deliberate.** The kernel's contract
 * spells `resolve(RegistryKey|string $key)` — *give me the handler registered under this prefix*.
 * This class had already spent the name on *dispatch this link to its handler*. Rather than break
 * every caller, `resolve()` is WIDENED contravariantly: hand it a {@see ShareLink} and it forwards
 * to {@see dispatch()}, hand it a key and it answers the contract. New code should say
 * `dispatch()`, which is the honest name for what the resolver route does.
 */
#[IsRegistry(
    root: 'beam.accounts.sharing.scopes',
    of: 'share-link scope handlers, one per `{prefix}:` scope a host can resolve',
    arity: RegistryArity::PickOne,
    entryType: Closure::class,
    onDuplicate: OnDuplicate::Supersede,
    optionality: Optionality::Optional,
    note: 'Last registration for a prefix wins — a host overriding a handler shipped by a package '
        .'below it is the seam, not an accident.',
)]
class ShareLinkScopes implements Gated, Registry
{
    /** @var BasicRegistry<Closure> prefix => fn(ShareLink, string, Request): mixed */
    protected BasicRegistry $entries;

    public function __construct()
    {
        $this->entries = BasicRegistry::for($this);
    }

    /** Register the handler for a scope prefix (e.g. 'composition'). Last registration wins. */
    public function handle(string $prefix, Closure $handler): void
    {
        $this->register($prefix, $handler);
    }

    /**
     * The contract's own door. {@see handle()} is the vocabulary this package's hosts already
     * speak and it stays; this is what a registrar or the index writes through.
     */
    public function register(RegistryKey|string $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        $this->entries->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * Whether a prefix has a handler.
     *
     * Guarded on {@see Key::tryParse()} because a scope prefix is read off a persisted
     * `share_links.scope` column: a row spelled outside the key grammar used to answer `false`
     * here and would now throw `InvalidRegistryKey`. An unparseable prefix is an unknown target,
     * which is exactly what `false` means.
     */
    public function hasHandler(string $prefix): bool
    {
        return $this->has($prefix);
    }

    /** @return string[] the registered scope prefixes, as the registrants spelled them */
    public function prefixes(): array
    {
        return $this->entries->relativeKeys();
    }

    /**
     * Dispatch a validated link to its host handler and return that handler's response. A scope
     * with no registered handler is a 404 — an unknown target, not a server error.
     */
    public function dispatch(ShareLink $link, Request $request): mixed
    {
        [$prefix, $ref] = array_pad(explode(':', $link->scope, 2), 2, '');

        $handler = $this->hasHandler($prefix) ? $this->entries->tryResolve($prefix) : null;

        abort_if($handler === null, 404);

        return $handler($link, $ref, $request);
    }

    public function has(RegistryKey|string $key): bool
    {
        if (is_string($key) && Key::tryParse($key) === null) {
            return false;
        }

        return $this->entries->has($key);
    }

    /**
     * Widened from the contract so the historical `resolve($link, $request)` spelling keeps
     * working — see the class docblock. Given a key, this is the contract's `resolve()` and it
     * throws `RegistryMiss` on a miss; given a {@see ShareLink} it is {@see dispatch()}.
     */
    public function resolve(RegistryKey|string|ShareLink $key, ?Request $request = null): mixed
    {
        if ($key instanceof ShareLink) {
            return $this->dispatch($key, $request ?? request());
        }

        return $this->entries->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->entries->tryResolve($key);
    }

    public function matches(RegistryKey|string $key): array
    {
        return $this->entries->matches($key);
    }

    public function keys(): array
    {
        return $this->entries->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->entries->unfiltered();
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->entries->authorizeWith($authorizer);

        return $this;
    }
}
