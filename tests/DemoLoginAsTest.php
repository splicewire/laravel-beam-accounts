<?php

use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Support\Demo;
use Splicewire\Beam\Accounts\Tests\Fixtures\RealmRoot;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * The engine-homed demo verification path: the role-derived demo roster, the
 * DemoTeamSeeder that provisions it, and the signed `splicewire:beam:accounts:login-as` route. Demo +
 * login-as moved down from the satellite (they reference only engine types + engine
 * config) so every consumer — platform or satellite — gets the same affordance behind
 * the one `beam.accounts.demo.enabled` gate.
 */

function seedDemo(): void
{
    app(DemoTeamSeeder::class)->run();
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
    config()->set('beam.accounts.demo.enabled', false);

    $this->get('/account/login-as/'.Role::Owner->value)->assertForbidden();
});

it('skips seeding when demo affordances are disabled', function () {
    config()->set('beam.accounts.demo.enabled', false);

    seedDemo();

    expect(User::where('email', 'like', 'demo-%')->count())->toBe(0);
});

// ── ACC-01: the Demo Team's grant-derived reach — no separate "Staff" subject needed ──────────

it('grants the demo team manage on every REGISTERED realm root (not just already-provisioned ones), making Owner/Admin ux-author-verifiable and Member denied', function () {
    // Deliberately create a root for only ONE of the four base-registered realms (site/operator/
    // tenant/user) — RealmReachGrant now eagerly provisions the rest via RealmRegistry rather than
    // only granting reach onto whatever RealmRoot rows already happened to exist.
    RealmRoot::create(['realm' => 'site']);

    seedDemo();

    $resolver = app(DefaultEntitlementResolver::class);

    $owner = User::where('email', Demo::email(Role::Owner->value))->first();
    $admin = User::where('email', Demo::email(Role::Admin->value))->first();
    $member = User::where('email', Demo::email(Role::Member->value))->first();

    expect($resolver->entitlementsFor($owner))->toEqualCanonicalizing([
        'ux.site.author', 'ux.operator.author', 'ux.tenant.author', 'ux.user.author',
        'ux.author', 'os.enter', 'os.operate',
    ]);
    expect($resolver->entitlementsFor($admin))->toEqualCanonicalizing([
        'ux.site.author', 'ux.operator.author', 'ux.tenant.author', 'ux.user.author',
        'ux.author', 'os.enter', 'os.operate',
    ]);
    expect($resolver->entitlementsFor($member))->toBe([]);
});

it('is idempotent about the realm-root grants it mints (re-running does not duplicate)', function () {
    RealmRoot::create(['realm' => 'site']);

    seedDemo();
    seedDemo();

    // One grant per base-registered realm (operator/tenant/site/user) — not just the one RealmRoot
    // fixture row pre-created above.
    $model = config('permission-cascade.grant_model');
    expect($model::query()->where('ability', AccessGrant::ABILITY_MANAGE)->count())->toBe(4);
});

it('grants reach on every registered realm even when no realm root has been provisioned yet (the fresh-install case)', function () {
    // No RealmRoot pre-created at all — BeamUxEntry::rootFor()-equivalent provisioning is normally
    // LAZY (first ask), so a fresh install would otherwise have nothing for RealmReachGrant to grant
    // against. It now eagerly provisions the RealmRegistry's base set instead, so a seeded demo/staff
    // team gets real reach without an author ever having visited an authoring surface first.
    seedDemo();

    $model = config('permission-cascade.grant_model');
    expect($model::query()->where('ability', AccessGrant::ABILITY_MANAGE)->count())->toBe(4);
});
