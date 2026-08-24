<?php

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\MeController;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\ReadContext;

/**
 * `me` — the caller's own identity projection as a particle resource, and the seam that lets a package
 * add its own slice of it (particle-contribution-seam 16/18).
 *
 * The point of the resource is the LAST test in this file: a package that owns a concern contributes
 * its slice without beam-accounts naming it. Everything above pins the shape that makes that possible.
 */
it('registers `me` as a particle resource', function () {
    $resource = app(ParticleResourceRegistry::class)->get(MeController::KEY);

    expect($resource->key)->toBe('me')
        ->and($resource->data)->toBe(AuthUserData::class)
        ->and($resource->modelClass())->toBe(config('auth.providers.users.model'));
});

it('declares `filterable: false`, because a singleton has no index to route', function () {
    // ⚠️ Load-bearing, and the default is the opposite. Ticket 14 found `filterable: true` routes an
    // index through the shipped `PayloadParticleReader::query()`, which THROWS — an armed default that
    // stays invisible for exactly as long as nothing registers the declaration.
    expect(app(ParticleResourceRegistry::class)->get(MeController::KEY)->filterable)->toBeFalse();
});

it('is read-only and unframed — writes stay on the existing PATCH /me', function () {
    $resource = app(ParticleResourceRegistry::class)->get(MeController::KEY);

    expect($resource->readOnly)->toBeTrue()
        ->and($resource->isFramed())->toBeFalse();
});

it('projects the identity core through the declaration, mirroring the caller bearer', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $resource = app(ParticleResourceRegistry::class)->get(MeController::KEY);

    $data = ($resource->project)($user);

    expect($data)->toBeInstanceOf(AuthUserData::class)
        ->and($data->email)->toBe('ada@example.test');
});

it('folds a contributed slice onto the projection, nested under its `as`', function () {
    // A stand-in for beam-commerce: a package that owns a concern, reaching down to a resource
    // beam-accounts owns. beam-accounts names nothing here and pre-declares no hook — which is the
    // whole ruling, and the reason two packages can now do this at once where the retired
    // single-slot port allowed exactly one.
    app(ResourceContributionRegistry::class)->register(new ResourceContribution(
        key: MeController::KEY,
        as: 'commerce',
        data: FakeSliceData::class,
        value: fn (Model $record, ReadContext $ctx, array $filters): FakeSliceData => new FakeSliceData('pro'),
    ));

    $user = User::create(['name' => 'Bo', 'email' => 'bo@example.test']);
    $resource = app(ParticleResourceRegistry::class)->get(MeController::KEY);

    $row = app(Splicewire\Beam\Particle\Contribution\ContributionProjector::class)
        ->apply(MeController::KEY, ($resource->project)($user), $user, ReadContext::detail([], $user))
        ->toArray();

    expect($row)->toHaveKey('commerce')
        ->and($row['commerce'])->toBe(['plan' => 'pro'])
        // The identity core is untouched, and the slice is NESTED rather than flat: `(key, as)`
        // uniqueness is what makes two packages claiming one sub-projection a loud conflict.
        ->and($row['email'])->toBe('bo@example.test')
        ->and($row)->not->toHaveKey('plan');
});

it('omits the key entirely when nobody contributes — absent, not present-and-null', function () {
    $user = User::create(['name' => 'Solo', 'email' => 'solo@example.test']);
    $resource = app(ParticleResourceRegistry::class)->get(MeController::KEY);

    $row = app(Splicewire\Beam\Particle\Contribution\ContributionProjector::class)
        ->apply(MeController::KEY, ($resource->project)($user), $user, ReadContext::detail([], $user))
        ->toArray();

    expect($row)->not->toHaveKey('commerce')->not->toHaveKey('embed');
});

class FakeSliceData extends Spatie\LaravelData\Data
{
    public function __construct(public string $plan) {}
}
