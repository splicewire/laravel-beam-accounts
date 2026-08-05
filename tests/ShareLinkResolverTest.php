<?php

use Splicewire\Beam\Accounts\Sharing\ShareLinks;
use Splicewire\Beam\Accounts\Sharing\ShareLinkScopes;

/**
 * Tracer 06 — the reusable `/s/{token}` front door owned by beam-accounts. Validates the token
 * and dispatches the scope to the HOST-registered handler; counts a use only on a successful
 * resolution. The host supplies the meaning of a scope (here a fake `demo` handler).
 */
beforeEach(function () {
    $this->links = app(ShareLinks::class);
    // A host registers what a scope resolves to; this stand-in echoes the ref + counts nothing itself.
    app(ShareLinkScopes::class)->handle('demo', fn ($link, string $ref) => response()->json(['ref' => $ref]));
});

it('resolves a valid token through the registered scope handler and counts a use', function () {
    $link = $this->links->create(null, 'demo:widget-1');

    $this->get("/s/{$link->token}")->assertOk()->assertJson(['ref' => 'widget-1']);

    expect($link->fresh()->use_count)->toBe(1);
});

it('404s an unknown token', function () {
    $this->get('/s/nope')->assertNotFound();
});

it('404s a scope with no registered handler, counting no use', function () {
    $link = $this->links->create(null, 'unregistered:x');

    $this->get("/s/{$link->token}")->assertNotFound();

    expect($link->fresh()->use_count)->toBe(0);
});

it('404s an expired link before dispatching', function () {
    $link = $this->links->create(null, 'demo:x', expiresAt: now()->subMinute());

    $this->get("/s/{$link->token}")->assertNotFound();
});
