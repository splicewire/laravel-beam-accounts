<?php

use Splicewire\Beam\Accounts\Concerns\HasMembers;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Particle\Backing\ConfiguredTokenBacking;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * particle-manifest-repatriation 06 — `tokens`, `invitations` and `members` each had TWO declarations in
 * the estate, and which one a host served was decided by provider boot order.
 *
 * The losing halves were `splicewire/tower`'s `Data\Frame\{Token,Invitation,Membership}ResourceData` —
 * the ANCESTORS these three DTOs were promoted from — plus, for the first two, a ~65-line restatement in
 * `~/Herd/splicewire-app`'s `ParticleServiceProvider`. All of them are deleted. This file pins the
 * properties that made the deletion safe, because every one of them was a HOST fact the restatements
 * were carrying and this package now has to carry instead.
 *
 * ⚠️ The tower fossils were worth deleting on their own account, not merely as duplication: tower's
 * `TokenResourceData` declared no `scope()` convention method at all. Tower deliberately excludes
 * `src/Data/Frame` from its attribute scan, so it never registered — but the day that exclusion was
 * forgotten, the winning `tokens` resource would have carried NO row-level boundary on a table shared by
 * every user in the estate. A declaration that is safe only because nobody loads it is not safe.
 */
it('backs tokens with the configured model rather than a class-string frozen into the attribute', function () {
    expect(app(ParticleResourceRegistry::class)->get('tokens')->backing)
        ->toBe(ConfiguredTokenBacking::class);
});

it('resolves the tokens backing to the host-configured PAT model', function () {
    // Config is read in the BACKING's constructor and the backing is container-resolved per request, so
    // it must be set before resolving — a test that resolves first passes against the defect.
    config()->set('beam.accounts.tokens.model', HostSuppliedToken::class);

    expect(app(BackingResolver::class)->resolve(ConfiguredTokenBacking::class)->modelClass())
        ->toBe(HostSuppliedToken::class);
});

it('falls back to this package own PAT model when the host names none', function () {
    config()->set('beam.accounts.tokens.model', null);

    expect(app(BackingResolver::class)->resolve(ConfiguredTokenBacking::class)->modelClass())
        ->toBe(PersonalAccessToken::class);
});

it('keeps the token revoke scope on the declaration, config-seamed', function () {
    // The scope closure is resolved by CONVENTION from `TokenData::scope()`, and it is the SOLE
    // boundary on the show/destroy subject resolution. `null` here is the security regression the
    // deleted tower declaration would have shipped.
    expect(app(ParticleResourceRegistry::class)->get('tokens')->scope)->not->toBeNull()
        ->and(method_exists(TokenData::class, 'scope'))->toBeTrue();
});

it('serves no per-record detail for tokens or invitations', function () {
    // ⚠️ `showable` defaults TRUE, so this is a promise made by NOT opting out — the same shape as
    // `filterable`. It has to live on the DECLARATION and not on a `frame.realm_resource_overrides`
    // entry: measured 2026-09-01, the frame transport resolves `$registry->get($resource)`
    // realm-unaware, so an overlay renders the nav correctly and still answers 200 on `records/{id}`.
    $registry = app(ParticleResourceRegistry::class);

    expect($registry->get('tokens')->showable)->toBeFalse()
        ->and($registry->get('invitations')->showable)->toBeFalse();
});

it('gives every contested key exactly one registration', function () {
    $registry = app(ParticleResourceRegistry::class);

    foreach (['tokens', 'invitations', 'members'] as $key) {
        expect($registry->superseded($key))->toBe([], "[{$key}] is shadowed again");
    }
});

it('names this package own Data classes on all three keys', function () {
    $registry = app(ParticleResourceRegistry::class);

    expect($registry->get('tokens')->data)->toBe(TokenData::class)
        ->and($registry->get('invitations')->data)->toBe(InvitationData::class)
        ->and($registry->get('members')->data)->toBe(MembershipData::class);
});

it('lets a team name the pivot column that means joined', function () {
    // The one fact that did NOT descend for free when tower's membership source was retired: tower read
    // `pivot->accepted_at`, this package defaults to `pivot->created_at`. A `belongsToMany` materialises
    // only the pivot columns its `withPivot()` names, so the wrong column reads NULL rather than
    // throwing — the column just goes blank behind a green suite.
    expect((new PlainTeamFixture)->memberJoinedColumn())->toBe('created_at')
        ->and((new AcceptanceStampedTeamFixture)->memberJoinedColumn())->toBe('accepted_at');
});

class HostSuppliedToken extends PersonalAccessToken {}

class PlainTeamFixture
{
    use HasMembers;
}

class AcceptanceStampedTeamFixture
{
    use HasMembers;

    public function memberJoinedColumn(): string
    {
        return 'accepted_at';
    }
}
