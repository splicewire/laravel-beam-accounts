<?php

use Rushing\Popcorn\Concerns\TraitMethods;
use Rushing\Popcorn\Contracts\ChainsTraitMethods;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/*
 * The provider's `boot` chain — the second full sweep of popcorn's trait-method chain, and the largest.
 *
 * `packageBooted()` carried a hand-written index of the provider's own parts: sixteen consecutive
 * `$this->boot*()` calls. Each concern now lives in the trait that owns it (`Concerns\Wires*`), declaring
 * `#[Chained('boot', order: N)]`, and the block is one `chainTraitMethods('boot')` call. The provider
 * drops 469 lines, 946 → 477.
 *
 * ⚠️ `packageRegistered()` was deliberately NOT converted. Its two `$this->register*()` calls are
 * interleaved with inline code rather than sitting in a block, and `bootAuthoringGates()` is a nested
 * helper called BY `bootAuthorization()` — so it travels inside `WiresAuthorization` carrying no
 * attribute at all. A second link would run it twice.
 *
 * ⚠️ **The order assertion is the whole safety of the conversion.** Sixteen links were sequenced by hand
 * and several are order-dependent — `bootAuthorization` defines the gates `bootFrameResources` and
 * `bootMeResource` later rely on; `bootRouteMacro` defines the macro `bootRoutes` immediately calls.
 * `pint`'s Laravel preset ships `ordered_traits`, which sorts a class's `use` statements alphabetically
 * and re-sorted this provider's block on the first run after the conversion. A chain resting on `use`
 * position would be resequenced by a formatter on an unrelated commit, with nothing failing.
 */

/** `packageBooted()`'s hand-written block, verbatim. Change only with the reason written down. */
const HISTORICAL_BOOT_ORDER = [
    'bootAuthorization',
    'bootMiddleware',
    'bootRouteMacro',
    'bootRoutes',
    'bootFortify',
    'bootApiGuardSeam',
    'bootApiGuardEnforcement',
    'bootDemo',
    'bootKeys',
    'bootOidc',
    'bootFrameResources',
    'bootMeResource',
    'bootSeed',
    'bootTeamsMigrations',
    'bootOperatorShell',
];

function bootChain(): array
{
    return array_map(
        fn ($method) => $method->getName(),
        TraitMethods::in(BeamAccountsServiceProvider::class, 'boot'),
    );
}

/**
 * Links added AFTER the conversion, in the order they were added. A new concern appends here with its
 * reason; it never edits {@see HISTORICAL_BOOT_ORDER}, which is a record of what the hand-written block
 * did and stops being evidence the moment it is treated as a live list.
 */
const APPENDED_BOOT_ORDER = [
    // beam-facade 159 — the `to_roles:` / `to_teams:` recipient kinds and the AccountsDirectory
    // binding. Last on purpose and order-independent by construction: it writes two config leaves and
    // one container binding, reads nothing any earlier link produced, and nothing later reads it
    // (RecipientKindRegistry reads through to the config repository at SEND time, not at boot).
    'bootNotifyRecipients',
];

it('resolves the boot chain in the order the hand-written block used, with later links appended', function () {
    expect(bootChain())->toBe([...HISTORICAL_BOOT_ORDER, ...APPENDED_BOOT_ORDER]);
});

it('keeps the historical block intact as a PREFIX rather than reshuffling it', function () {
    // The order assertion above would also pass if a later link were interleaved into the middle and
    // the constant edited to match. This one says the sixteen hand-sequenced links still run first,
    // in their hand-sequenced order, whatever has been appended since.
    expect(array_slice(bootChain(), 0, count(HISTORICAL_BOOT_ORDER)))->toBe(HISTORICAL_BOOT_ORDER);
});

it('runs bootRouteMacro before bootRoutes, which calls the macro it defines', function () {
    // Spelled out separately from the whole-list assertion because this pair is the one whose
    // inversion fails loudly rather than subtly — and it is the pair a formatter would invert.
    $chain = bootChain();

    expect(array_search('bootRouteMacro', $chain, true))
        ->toBeLessThan(array_search('bootRoutes', $chain, true));
});

it('keeps bootAuthoringGates OUT of the chain', function () {
    // It is called BY bootAuthorization(), not by the chain. Attributing it would run it twice —
    // and it defines Gates, so the second run would be a redefinition, not a no-op.
    expect(bootChain())->not->toContain('bootAuthoringGates');
});

it('is not empty', function () {
    // Guards the dead-seam shape: a rename that unhooked every link would leave the order assertion
    // comparing two empty arrays, and a provider that boots clean wiring nothing.
    expect(bootChain())->toHaveCount(count(HISTORICAL_BOOT_ORDER) + count(APPENDED_BOOT_ORDER));
});

it('declares the contract so a detector can find it', function () {
    expect(app()->getProvider(BeamAccountsServiceProvider::class))
        ->toBeInstanceOf(ChainsTraitMethods::class);
});
