<?php

use Illuminate\Http\Request;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Sharing\ShareLinkScopes;

/*
 * registry-kernel ticket 38 — beam-accounts' two registries on the popcorn kernel.
 *
 * The first test is the harness TRIPWIRE and it guards every other index assertion in this package:
 * a testbench app that does not boot `PopcornServiceProvider` gets an auto-resolvable but UNSHARED
 * `RegistryIndex`, so every `describe()` lands on a throwaway and a suite asserting index membership
 * stays green over nothing (27 D3, measured three times before this package).
 */

it('shares ONE RegistryIndex — the harness tripwire every other assertion here rests on', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('describes both of the package roots into the index at boot', function () {
    $index = app(RegistryIndex::class);

    expect($index->has('beam.accounts.sharing.scopes'))->toBeTrue()
        ->and($index->has('beam.accounts.entitlements.bundles'))->toBeTrue()
        ->and($index->routeTo('beam.accounts.sharing.scopes'))->toBe(app(ShareLinkScopes::class))
        ->and($index->routeTo('beam.accounts.entitlements.bundles'))->toBe(app(BundleRegistry::class));
});

it('declares what each root holds, so `popcorn:registries` can answer for beam-accounts', function () {
    $index = app(RegistryIndex::class);

    expect($index->declarationAt('beam.accounts.sharing.scopes')->entryType)->toBe(Closure::class)
        ->and($index->declarationAt('beam.accounts.entitlements.bundles')->entryType)->toBe('list<string>');
});

// ── ShareLinkScopes — hand-registered at boot by the host ─────────────────────────────────────

it('round-trips a scope handler through the port vocabulary and the contract alike', function () {
    $scopes = app(ShareLinkScopes::class);
    $handler = fn ($link, string $ref) => $ref;

    $scopes->handle('composition', $handler);

    // The port's own vocabulary (what every host speaks) …
    expect($scopes->hasHandler('composition'))->toBeTrue()
        ->and($scopes->prefixes())->toBe(['composition'])
        // … and the kernel's contract, over the SAME entry, absolute out.
        ->and($scopes->has('beam.accounts.sharing.scopes.composition'))->toBeTrue()
        ->and($scopes->resolve('composition'))->toBe($handler)
        ->and((string) $scopes->keys()[0])->toBe('beam.accounts.sharing.scopes.composition');
});

it('keeps registration ORDER, and a re-registration supersedes IN PLACE', function () {
    $scopes = app(ShareLinkScopes::class);

    $scopes->handle('alpha', fn () => 'a');
    $scopes->handle('beta', fn () => 'b');
    expect($scopes->prefixes())->toBe(['alpha', 'beta']);

    // ⚠️ A PHP array assignment held `alpha`'s slot, and registry-kernel 62 made supersession do the
    // same — this assertion read `['beta', 'alpha']` while the kernel displaced-and-appended. PickOne
    // here, so nothing enumerates for meaning — pinned so a later arity change cannot do it silently.
    $scopes->handle('alpha', fn () => 'a2');
    expect($scopes->prefixes())->toBe(['alpha', 'beta'])
        ->and(($scopes->resolve('alpha'))())->toBe('a2');
});

it('answers false rather than throwing for a prefix outside the key grammar', function () {
    // A scope prefix is read off a persisted `share_links.scope` column, so it is not guaranteed to
    // be a legal Key. The old array lookup was quiet; `Key::of()` throws. Unknown target = 404.
    expect(app(ShareLinkScopes::class)->hasHandler('Not A Key@2'))->toBeFalse();
});

it('throws RegistryMiss from the contract door for an unregistered prefix', function () {
    app(ShareLinkScopes::class)->resolve('nobody-registered-this');
})->throws(RegistryMiss::class);

it('still dispatches a ShareLink through the widened resolve(), as the controller always did', function () {
    app(ShareLinkScopes::class)->handle('demo', fn (ShareLink $link, string $ref) => "hit:{$ref}");

    $link = new ShareLink(['token' => 't', 'scope' => 'demo:widget-1']);

    expect(app(ShareLinkScopes::class)->dispatch($link, Request::create('/s/t')))->toBe('hit:widget-1')
        ->and(app(ShareLinkScopes::class)->resolve($link, Request::create('/s/t')))->toBe('hit:widget-1');
});

// ── BundleRegistry — a ConfigRegistry, whose storage is the host's config array ───────────────

it('is a registry whose storage stayed exactly where it was', function () {
    config()->set('beam.accounts.entitlements.bundles', ['staff' => ['author-ux']]);

    $bundles = app(BundleRegistry::class);

    expect($bundles)->toBeInstanceOf(Registry::class)
        ->and($bundles->names())->toBe(['staff'])
        ->and($bundles->keysFor('staff'))->toBe(['author-ux'])
        ->and($bundles->resolve('beam.accounts.entitlements.bundles.staff'))->toBe(['author-ux']);
});

it('reads THROUGH to config, so a bundle declared after the singleton resolved is visible', function () {
    config()->set('beam.accounts.entitlements.bundles', ['staff' => ['author-ux']]);

    $bundles = app(BundleRegistry::class);
    expect($bundles->has('late'))->toBeFalse();

    // The old constructor snapshotted the array; a late registrant was invisible forever (A8).
    config()->set('beam.accounts.entitlements.bundles', [
        'staff' => ['author-ux'],
        'late' => ['arrived'],
    ]);

    expect($bundles->has('late'))->toBeTrue()
        ->and($bundles->keysFor('late'))->toBe(['arrived'])
        ->and($bundles->names())->toBe(['staff', 'late']);
});

it('writes back into the config array through the contract door', function () {
    config()->set('beam.accounts.entitlements.bundles', []);

    app(BundleRegistry::class)->register('minted', ['a', 'b']);

    expect(config('beam.accounts.entitlements.bundles'))->toBe(['minted' => ['a', 'b']])
        ->and(app(BundleRegistry::class)->keysFor('minted'))->toBe(['a', 'b']);
});

it('answers the empty set rather than throwing for a bundle name outside the key grammar', function () {
    // A bundle name reaches here off a host's plan model as often as off its config.
    expect(app(BundleRegistry::class)->keysFor('Pro Plan@2'))->toBe([])
        ->and(app(BundleRegistry::class)->has('Pro Plan@2'))->toBeFalse();
});
