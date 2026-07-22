<?php

use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Support\Demo;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * The engine-homed demo verification path: the role-derived demo roster, the
 * DemoTeamSeeder that provisions it, and the signed `account:login-as` route. Demo +
 * login-as moved down from the satellite (they reference only engine types + engine
 * config) so every consumer — platform or satellite — gets the same affordance behind
 * the one `splicewire.account.demo.enabled` gate.
 */

function seedDemo(): void
{
    (new DemoTeamSeeder(app(TeamProvisioner::class)))->run();
}

it('derives the demo roster from the Role enum plus a solo subject', function () {
    // No parallel list: the roster IS Role::values() (each a shared-team subject) plus the
    // one structural extra, solo. Add a Role case → the roster grows with no seeder edit.
    expect(Demo::keys())->toBe([...Role::values(), 'solo']);

    foreach (Role::cases() as $role) {
        expect(Demo::has($role->value))->toBeTrue();
        expect(Demo::isShared($role->value))->toBeTrue();
        expect(Demo::roleFor($role->value))->toBe($role);
    }

    expect(Demo::has('solo'))->toBeTrue();
    expect(Demo::isShared('solo'))->toBeFalse();
});

it('provisions one shared-team subject per role plus a solo team-of-one', function () {
    seedDemo();

    foreach (Demo::keys() as $key) {
        expect(User::where('email', Demo::email($key))->exists())->toBeTrue();
    }

    $owner = User::where('email', Demo::email(Role::Owner->value))->first();

    // Every role subject sits on the one shared Demo Team, holding exactly its role.
    foreach (Role::cases() as $role) {
        $user = User::where('email', Demo::email($role->value))->first();
        expect($user->current_team_id)->toBe($owner->current_team_id);

        $membership = $user->memberships()->where('team_id', $owner->current_team_id)->first();
        expect($membership->role)->toBe($role->value);
    }

    // Solo gets its own personal team-of-one — the default satellite shape.
    $solo = User::where('email', Demo::email('solo'))->first();
    expect($solo->personalTeam())->not->toBeNull();
    expect($solo->personalTeam()->personal_team)->toBeTrue();
    expect($solo->current_team_id)->not->toBe($owner->current_team_id);
});

it('is idempotent', function () {
    seedDemo();
    seedDemo();

    expect(User::where('email', 'like', 'demo-%')->count())->toBe(count(Demo::keys()));
});

it('logs in as a demo subject through the signed route', function () {
    seedDemo();

    $this->get('/account/login-as/'.Role::Owner->value)->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->email)->toBe(Demo::email(Role::Owner->value));
});

it('404s an unknown demo subject', function () {
    seedDemo();

    $this->get('/account/login-as/nobody')->assertNotFound();
});

it('403s the login-as route when demo affordances are disabled', function () {
    config()->set('splicewire.account.demo.enabled', false);

    $this->get('/account/login-as/'.Role::Owner->value)->assertForbidden();
});

it('skips seeding when demo affordances are disabled', function () {
    config()->set('splicewire.account.demo.enabled', false);

    seedDemo();

    expect(User::where('email', 'like', 'demo-%')->count())->toBe(0);
});
