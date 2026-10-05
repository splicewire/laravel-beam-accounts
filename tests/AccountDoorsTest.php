<?php

use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Features;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Doors\AccountDoors;
use Splicewire\Beam\Accounts\Doors\Door;
use Splicewire\Beam\Accounts\Doors\DoorClosed;
use Splicewire\Beam\Accounts\Doors\NewUserData;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * purchase-walkthrough BUY-05 (M10, rule BUY-9): who may become a user is ONE declared policy,
 * `beam.accounts.doors = {registration, oauth: {providers, create, domains}, operator}`, and `AccountDoors::create`
 * is the only central User create. Fortify's registration feature follows `registration`; the CreatesNewUsers
 * binding is door-checked; UserPolicy::create reads `operator`. Package default: closed / never / true.
 */

function doors(): AccountDoors
{
    return app(AccountDoors::class);
}

function newUser(string $email = 'ada@acme.test'): NewUserData
{
    return new NewUserData(name: 'Ada', email: $email, password: 'a-long-password-1');
}

it('ships every door shut except the operator\'s', function () {
    // The SHIPPED default, read from the package's config file (this testbench opens registration; TestCase).
    config(['beam.accounts.doors' => (require __DIR__.'/../config/beam/accounts.php')['doors']]);

    expect(doors()->policy()->toArray())->toBe([
        'registration' => 'closed',
        'oauth' => ['providers' => [], 'create' => 'never', 'domains' => []],
        'operator' => true,
    ]);
});

it('admits registration only when it is open', function (string $mode, bool $admitted) {
    config(['beam.accounts.doors.registration' => $mode]);

    expect(doors()->admit(Door::Register, 'ada@acme.test')->admitted)->toBe($admitted);
})->with([['closed', false], ['open', true]]);

it('admits an OAuth sign-up by exact, case-insensitive domain, and never by suffix', function () {
    config(['beam.accounts.doors.oauth' => ['providers' => ['google'], 'create' => 'domains', 'domains' => ['@Acme.test', 'example.org']]]);

    expect(doors()->admit(Door::OAuth, 'ada@acme.test')->admitted)->toBeTrue()
        ->and(doors()->admit(Door::OAuth, 'bo@EXAMPLE.org')->admitted)->toBeTrue()
        ->and(doors()->admit(Door::OAuth, 'eve@evil-acme.test')->admitted)->toBeFalse()
        ->and(doors()->admit(Door::OAuth, 'eve@acme.test.evil')->admitted)->toBeFalse();
});

it('admits no OAuth sign-up when OAuth creation is never, or no list is declared', function (array $oauth) {
    config(['beam.accounts.doors.oauth' => $oauth]);

    expect(doors()->admit(Door::OAuth, 'ada@acme.test')->admitted)->toBeFalse();
})->with([
    'never' => [['providers' => ['google'], 'create' => 'never', 'domains' => ['acme.test']]],
    'domains, empty list' => [['providers' => ['google'], 'create' => 'domains', 'domains' => []]],
]);

it('admits an invitation claim only for the invited address, unused and unexpired', function () {
    $owner = User::create(['name' => 'Owner', 'email' => 'owner@acme.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Invitation::create(['team_id' => $team->id, 'email' => 'ada@acme.test', 'role' => 'member', 'token' => 'tok']);
    Invitation::create(['team_id' => $team->id, 'email' => 'used@acme.test', 'role' => 'member', 'token' => 'used', 'accepted_at' => now()]);

    expect(doors()->admit(Door::Invite, 'ADA@acme.test', 'tok')->admitted)->toBeTrue()
        ->and(doors()->admit(Door::Invite, 'eve@acme.test', 'tok')->admitted)->toBeFalse()
        ->and(doors()->admit(Door::Invite, 'ada@acme.test', 'nope')->admitted)->toBeFalse()
        ->and(doors()->admit(Door::Invite, 'ada@acme.test')->admitted)->toBeFalse()
        ->and(doors()->admit(Door::Invite, 'used@acme.test', 'used')->admitted)->toBeFalse();

    $this->travel(30)->days();
    expect(doors()->admit(Door::Invite, 'ada@acme.test', 'tok')->admitted)->toBeFalse();
});

it('admits the operator door per policy, and a service user always', function () {
    expect(doors()->admit(Door::Operator, 'ada@acme.test')->admitted)->toBeTrue();
    config(['beam.accounts.doors.operator' => false]);
    expect(doors()->admit(Door::Operator, 'ada@acme.test')->admitted)->toBeFalse()
        ->and(doors()->admit(Door::Service, 'svc@acme.test')->admitted)->toBeTrue();
});

it('creates a user only through an admitted door, and nothing through a closed one', function () {
    config(['beam.accounts.doors.registration' => 'closed']);
    expect(fn () => doors()->create(Door::Register, newUser()))->toThrow(DoorClosed::class);
    expect(User::query()->where('email', 'ada@acme.test')->exists())->toBeFalse();

    config(['beam.accounts.doors.registration' => 'open']);
    $user = doors()->create(Door::Register, newUser());

    expect($user->email)->toBe('ada@acme.test')
        ->and(Team::query()->where('user_id', $user->getKey())->exists())->toBeFalse('No personal team at registration (lead ruling (a)).');
});

it('drops Fortify registration from any feature list unless registration is open', function () {
    config(['beam.accounts.doors.registration' => 'closed']);
    $listed = [Features::registration(), Features::resetPasswords()];

    expect(doors()->fortifyFeatures($listed))->toBe([Features::resetPasswords()]);

    config(['beam.accounts.doors.registration' => 'open']);
    expect(doors()->fortifyFeatures([Features::resetPasswords()]))->toBe([Features::resetPasswords(), Features::registration()]);
});

it('leaves a host\'s own Fortify feature list alone while it has not declared its registration door', function () {
    // A host that has not declared beam.accounts.doors.registration keeps exactly the features it lists, so adopting
    // this package changes no live host until it declares (build-qa on 56ea4f5: the closed default reached them).
    config(['beam.accounts.doors.registration' => null]);
    $listed = [Features::registration(), Features::resetPasswords()];

    expect(doors()->declared())->toBeFalse()
        ->and(doors()->fortifyFeatures($listed))->toBe($listed)
        ->and(doors()->admit(Door::Register, 'ada@acme.test')->admitted)->toBeFalse('Undeclared admits nothing through the package action.');
});

it('sets Fortify\'s registration feature from the policy at boot', function () {
    // This testbench boots with registration OPEN (TestCase); the closed path is covered by fortifyFeatures() above.
    expect(config('fortify.features'))->toContain(Features::registration());
});

it('binds Fortify registration to the door-checked action', function () {
    $action = app(CreatesNewUsers::class);
    $input = ['name' => 'Ada', 'email' => 'ada@acme.test', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'];
    config(['beam.accounts.doors.registration' => 'closed']);

    expect(fn () => $action->create($input))->toThrow(DoorClosed::class);

    config(['beam.accounts.doors.registration' => 'open']);
    expect($action->create($input)->email)->toBe('ada@acme.test');
});

it('lets only a Root operator create users, and only while the operator door is open', function () {
    $root = User::create(['name' => 'Root', 'email' => 'root@acme.test', 'password' => 'x']);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(config('permission.models.role'))::create(['name' => 'Root', 'guard_name' => 'web']);
    $root->assignRole('Root');
    $member = User::create(['name' => 'Member', 'email' => 'member@acme.test', 'password' => 'x']);

    expect(app(UserPolicy::class)->create($root->fresh()))->toBeTrue()
        ->and(app(UserPolicy::class)->create($member))->toBeFalse();

    config(['beam.accounts.doors.operator' => false]);
    expect(app(UserPolicy::class)->create($root->fresh()))->toBeFalse();
});
