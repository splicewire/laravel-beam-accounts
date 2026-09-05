<?php

use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * ## This package seats NO nav section, and that is the measurement rather than an oversight
 *
 * `NavSectionRegistry` (beam 68289d4) lets a package seat the top-level nav section its own
 * `#[ParticleResource(section:)]` declarations name. beam-ux seats `ops` and `authoring` through it;
 * beam-calendars seats `calendars`. The obvious next question was which sections beam-accounts should
 * seat, and the answer measured 2026-09-05 is **none**: not one of the seven resources this package
 * registers — `users`, `tokens`, `teams`, `invitations`, `access-grants`, `view-requests`, `members` —
 * declares a `section:` at all.
 *
 * What four of them declare instead is `group:` (`Settings`, `Platform`). That is a DIFFERENT mechanism:
 * `group` labels a resource inside Frame's resource index, `section` is the nav join key
 * `FrameResourcesInvocable` matches on. Reading one for the other is exactly how a seat gets invented
 * for a page nobody serves.
 *
 * So a `settings` seat here would be a nav entry this package cannot fill. `FrameResourcesInvocable`
 * attaches children by `$def->nav->section === $section`; with no resource naming a section, the seat
 * collects nothing, `FrameNavContribution` drops it as an empty contributed seat, and the only thing
 * shipped is a declaration that reads as intent and does nothing. Hand-authored `static:` rows would not
 * save it either — `FrameNavContribution::pruneUnbound()` drops a contributed child whose `routeName` is
 * not in the host's RouteContext, and `/settings/profile` + `/settings/security`
 * (`routes/account.php:12-20`) are controller pages, not realm resources, so they are never in it.
 *
 * That leaves two honest futures, and BOTH are somebody's deliberate decision rather than this test's:
 * declare `section:` on the resources that belong in a nav section and seat it here, or leave the
 * settings surface as the host IA it is today (the flagship renders it through `realm_resource_overrides`
 * under its own shell, and `tokens`/`members`/`invitations` are realm members there already). This test
 * exists so the first of those cannot happen by half — a `section:` added with no seat behind it is the
 * defect the seam was built to close, and it would otherwise be silent.
 *
 * ⚠️ It asserts an EMPTY population, so it must first prove it looked. A registry that came back empty
 * would satisfy "no resource declares a section" for the wrong reason, which is this estate's signature
 * failure mode.
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

it('declares no `section:` on any resource, so there is no section for it to seat', function () {
    $withSection = [];

    foreach (accountsResources() as $resource) {
        /** @var ParticleResource $resource */
        if ($resource->section !== null) {
            $withSection[$resource->key] = $resource->section;
        }
    }

    expect($withSection)->toBe([], 'A resource now declares a section. Seat it from '
        .'BeamAccountsServiceProvider through NavSectionRegistry, or the section is declared and '
        .'invisible — see the docblock at the top of this file.');
});

it('seats nothing into the nav-section registry, which is the consequence of the line above', function () {
    $registry = app(NavSectionRegistry::class);

    $seated = array_values(array_filter(
        $registry->all(),
        fn ($section): bool => str_starts_with($section->key, 'account')
            || in_array($section->key, ['settings', 'users', 'teams', 'tokens'], true),
    ));

    expect($seated)->toBe([]);
});
