<?php

use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * ## This package seats ONE nav section: People (ux-walkthrough UX-09, IA-10; lead 09:01Z ruling 1)
 *
 * Until UX-09 it seated none, measured 2026-09-05: no resource here declared a `section:`, and a seat with nothing to
 * collect is a declaration that does nothing. That test named the honest future: declare `section:` on the resources
 * that belong in a nav section AND seat it here, never one without the other. The operator rail's People task section
 * is that: `users` and `teams` declare `section: people`, and this package seats `people` in the operator realm for every
 * host. A host with `collapse_user_realm` (the flagship) lists neither resource in its operator realm, so the seat
 * collects nothing there and the projection drops it.
 *
 * Still asserted: no OTHER resource declares a section, because a section with no seat behind it is invisible.
 */
/**
 * THIS package's registered resources, scoped by the namespace of the Data class that declares each.
 *
 * The scope is load-bearing, not tidiness: the harness boots `BeamServiceProvider`, which discovers
 * beam-core's OWN declarations, so the unscoped registry also carries `schemas` (`authoring`),
 * `git-repo` and `notification-statuses` (`ops`) and `hooks` (`platform`). Asserting over all of them
 * would fail on four resources this package does not own and cannot seat.
 *
 * @return list<ParticleResource>
 */
function accountsResources(): array
{
    return array_values(array_filter(
        app(ParticleResourceRegistry::class)->all(),
        fn (ParticleResource $resource): bool => is_string($resource->data)
            && str_starts_with($resource->data, 'Splicewire\\Beam\\Accounts\\'),
    ));
}

it('registers resources at all, so the assertion below is measuring something', function () {
    // Measured 2026-09-05: access-grants, invitations, me, members, teams, tokens, users,
    // view-requests. An EXACT count rather than a non-empty check, because the next test asserts an
    // empty set and would be satisfied for the wrong reason by a registry that came back short — a
    // config gate flipping off half the surface reads identically to "nothing declares a section".
    expect(accountsResources())->toHaveCount(8);
});

it('declares section `people` on users and teams and on nothing else', function () {
    $withSection = [];

    foreach (accountsResources() as $resource) {
        /** @var ParticleResource $resource */
        if ($resource->section !== null) {
            $withSection[$resource->key] = $resource->section;
        }
    }
    ksort($withSection);

    expect($withSection)->toBe(['teams' => 'people', 'users' => 'people'], 'Only users and teams sit in a nav section. '
        .'Seat any other section from BeamAccountsServiceProvider through NavSectionRegistry, or it is invisible.');
});

it('seats People in the operator realm as a product task section, and nothing else', function () {
    $seated = array_values(array_filter(
        app(NavSectionRegistry::class)->all(),
        fn ($section): bool => str_starts_with($section->key, 'account')
            || in_array($section->key, ['settings', 'users', 'teams', 'tokens', 'people'], true),
    ));

    expect(array_map(fn ($s): array => [$s->realm, $s->key, $s->label, $s->audience->value], $seated))
        ->toBe([['operator', 'people', 'People', 'product']]);
});
