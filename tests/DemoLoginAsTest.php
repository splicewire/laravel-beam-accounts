<?php

use Illuminate\Support\Facades\URL;
use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
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

/**
 * The signed link the affordance actually ships — `splicewire:beam:accounts:login-as` mints exactly
 * this URL.
 *
 * These tests used to hit the bare route, because the retired hand-rolled gate returned early in
 * `local`/`testing`. That carve-out is gone (api-surface-coherence ticket 95): authorization is now
 * DECLARED on the operation (`ability: 'loginAs'` + `signed: true`) and a framework gate has no
 * environment branch — which also means an `APP_ENV=local` host no longer lets an unauthenticated
 * request assume any identity by id. So the suite exercises the credential the affordance really
 * uses, rather than a bypass no production caller ever holds.
 */
function signedLoginAs(int|string $id, int $minutes = 30): string
{
    return URL::temporarySignedRoute('users.op.login-as', now()->addMinutes($minutes), ['id' => $id]);
}

it('derives the demo roster from the Role enum plus a solo subject', function () {
    // No parallel list: the roster IS Role::values() (each a shared-team subject) plus the
    // one structural extra, solo. Add a Role case → the roster grows with no seeder edit.
    expect(BeamDemo::keys())->toBe([...Role::values(), 'solo']);

    foreach (Role::cases() as $role) {
        expect(BeamDemo::has($role->value))->toBeTrue();
        expect(BeamDemo::isShared($role->value))->toBeTrue();
        expect(BeamDemo::roleFor($role->value))->toBe($role);
    }

    expect(BeamDemo::has('solo'))->toBeTrue();
    expect(BeamDemo::isShared('solo'))->toBeFalse();
});

it('provisions one shared-team subject per role plus a solo team-of-one', function () {
    seedDemo();

    foreach (BeamDemo::keys() as $key) {
        expect(User::where('email', BeamDemo::email($key))->exists())->toBeTrue();
    }

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->first();

    // Every role subject sits on the one shared Demo Team, holding exactly its role.
    foreach (Role::cases() as $role) {
        $user = User::where('email', BeamDemo::email($role->value))->first();
        expect($user->current_team_id)->toBe($owner->current_team_id);

        $membership = $user->memberships()->where('team_id', $owner->current_team_id)->first();
        expect($membership->role)->toBe($role->value);
    }

    // Solo gets its own personal team-of-one — the default satellite shape.
    $solo = User::where('email', BeamDemo::email('solo'))->first();
    expect($solo->personalTeam())->not->toBeNull();
    expect($solo->personalTeam()->personal_team)->toBeTrue();
    expect($solo->current_team_id)->not->toBe($owner->current_team_id);
});

it('is idempotent', function () {
    seedDemo();
    seedDemo();

    expect(User::where('email', 'like', 'demo-%')->count())->toBe(count(BeamDemo::keys()));
});

it('logs in as a demo subject through the operation route', function () {
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $this->get(signedLoginAs($owner->getKey()))->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->email)->toBe(BeamDemo::email(Role::Owner->value));
});

it('404s an id that resolves to no user', function () {
    seedDemo();

    // The op resolves {id} against the user model, so an unknown SUBJECT is no longer the failure
    // mode — an unresolvable id is, and findOrFail is what answers it.
    $this->get(signedLoginAs(999999))->assertNotFound();
});

it('refuses an UNSIGNED login-as from a caller the policy denies, in every environment', function () {
    // The regression this pins is the one ticket 95 removed: the retired gate returned early in
    // `local`/`testing`, so an unauthenticated GET could assume any identity by id on a local host.
    // The declared gate has no environment branch, so the same request is now a 403.
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $this->get('/users/'.$owner->getKey().'/op/login-as')->assertForbidden();

    expect(auth()->check())->toBeFalse();
});

it('refuses a signed link whose signature has been tampered with', function () {
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $this->get(signedLoginAs($owner->getKey()).'0')->assertForbidden();
});

it('refuses a signed link after it has expired', function () {
    // Replay is bounded by expiry and not otherwise prevented — this is the bound, asserted.
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $url = signedLoginAs($owner->getKey(), minutes: 5);

    $this->travelTo(now()->addMinutes(10));

    $this->get($url)->assertForbidden();
});

it('403s the login-as operation when demo affordances are disabled', function () {
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    config()->set('beam.accounts.demo.enabled', false);

    $this->get(signedLoginAs($owner->getKey()))->assertForbidden();
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

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->first();
    $admin = User::where('email', BeamDemo::email(Role::Admin->value))->first();
    $member = User::where('email', BeamDemo::email(Role::Member->value))->first();

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
