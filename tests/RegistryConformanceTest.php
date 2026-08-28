<?php

use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;

/*
 * registry-kernel ticket 38 — beam-accounts' registry on the popcorn kernel.
 *
 * The first test is the harness TRIPWIRE and it guards every other index assertion in this package:
 * a testbench app that does not boot `PopcornServiceProvider` gets an auto-resolvable but UNSHARED
 * `RegistryIndex`, so every `describe()` lands on a throwaway and a suite asserting index membership
 * stays green over nothing (27 D3, measured three times before this package).
 */

it('shares ONE RegistryIndex — the harness tripwire every other assertion here rests on', function () {
    expect(app(RegistryIndex::class))->toBe(app(RegistryIndex::class));
});

it('describes the package root into the index at boot', function () {
    $index = app(RegistryIndex::class);

    expect($index->has('beam.accounts.entitlements.bundles'))->toBeTrue()
        ->and($index->routeTo('beam.accounts.entitlements.bundles'))->toBe(app(BundleRegistry::class));
});

it('declares what the root holds, so `popcorn:registries` can answer for beam-accounts', function () {
    $index = app(RegistryIndex::class);

    expect($index->declarationAt('beam.accounts.entitlements.bundles')->entryType)->toBe('list<string>');
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
