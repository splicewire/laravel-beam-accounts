<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Rushing\PermissionCascade\Contracts\AccessGrant;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Models\User as BeamUser;
use Splicewire\Beam\Accounts\QueryBuilders\SignedLoginAsSubject;
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

/*
 * ── The refusals, and why they answer 404 rather than 403 ────────────────────────────────────────
 *
 * These three cases asserted `assertForbidden()` from the day they were written and had NEVER RUN:
 * every one of them died on `no such table: users` (beam-facade 172), because the `users` resource's
 * `backing:` is pinned to the `central` connection and this harness's `central` was a second,
 * separate `:memory:` database. So the 403 was a statement of intent, not a measurement.
 *
 * With the harness joined and {@see SignedLoginAsSubject} in place, the measured answer is 404, and
 * that is the RIGHT answer rather than a regression. The refusal happens one step earlier than the
 * ability check: a request with no valid signature is an ordinary guest, the users resource's row
 * scope resolves to nothing for a guest, and `findOrFail` answers 404. A 403 would have been an
 * EXISTENCE ORACLE — it would confirm to an unauthenticated caller that a given id names a real
 * user, on the one endpoint whose whole purpose is becoming that user.
 *
 * What each case is actually pinning is unchanged and is asserted directly: the request is refused
 * AND no session is authenticated.
 */

it('refuses an UNSIGNED login-as in every environment, and mints no session', function () {
    // The regression this pins is the one ticket 95 removed: the retired gate returned early in
    // `local`/`testing`, so an unauthenticated GET could assume any identity by id on a local host.
    // The declared gate has no environment branch, and neither does the row scope.
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $this->get('/users/'.$owner->getKey().'/op/login-as')->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

it('refuses a signed link whose signature has been tampered with, and mints no session', function () {
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $this->get(signedLoginAs($owner->getKey()).'0')->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

it('refuses a signed link after it has expired, and mints no session', function () {
    // Replay is bounded by expiry and not otherwise prevented — this is the bound, asserted.
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    $url = signedLoginAs($owner->getKey(), minutes: 5);

    $this->travelTo(now()->addMinutes(10));

    $this->get($url)->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

/*
 * ── The signed-subject hole is exactly one row wide (beam-facade 172(b)) ─────────────────────────
 *
 * GATE POSTURE, stated because AGENTS.md requires it of any authorization measurement: this file —
 * and the whole beam-accounts suite — installs NO `Gate::before` of any kind. `grep -rn 'Gate::before'
 * src/ tests/` returns nothing. Every status below is what a real deny-by-default host answers.
 */

it('lets a validly-signed request resolve the ONE subject its signature names, and no other', function () {
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();
    $member = User::where('email', BeamDemo::email(Role::Member->value))->firstOrFail();

    // Keep the owner's signature, swap the id in the path. The signature covers the whole URL, so
    // this is not a valid signature for the member's route — the request falls back to the ordinary
    // guest scope and resolves nothing. This is the check that makes `whereKey()` safe.
    $forged = str_replace(
        '/users/'.$owner->getKey().'/',
        '/users/'.$member->getKey().'/',
        signedLoginAs($owner->getKey()),
    );

    $this->get($forged)->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

it('does not widen the users row scope on any OTHER mount, even holding a real login-as signature', function () {
    // This is the claim the whole fix rests on, so it is measured against the SCOPE CLOSURE rather
    // than against a status code: the package harness mounts only the login-as op (the users REST
    // resource is a host mount), so asserting 404 on `/users` here would be a route-missing 404 and
    // would prove nothing. A probe route that runs the real closure proves the actual thing.
    seedDemo();

    $owner = User::where('email', BeamDemo::email(Role::Owner->value))->firstOrFail();

    Route::middleware('web')->get('/probe-users-scope', fn () => [
        'visible' => UserData::scope(BeamUser::query())->count(),
    ]);

    // The signer's two reserved parameters, lifted off a GENUINELY VALID login-as link and pasted
    // onto another mount. Two independent things refuse them: `hasValidSignature()` is computed over
    // the full URL including the path, and SignedLoginAsSubject checks the route's own
    // `_particle_op_resource`/`_particle_op_name` defaults before it ever looks at the signature.
    $query = parse_url(signedLoginAs($owner->getKey()), PHP_URL_QUERY);

    $this->getJson('/probe-users-scope?'.$query)->assertOk()->assertJson(['visible' => 0]);

    // And with nothing at all — the unchanged guest fail-safe, `whereRaw('1 = 0')`.
    $this->getJson('/probe-users-scope')->assertOk()->assertJson(['visible' => 0]);

    expect(auth()->check())->toBeFalse();
});

it('reports no signed subject for a request that is not the login-as mount', function () {
    // The three ways SignedLoginAsSubject::id() must answer null, asserted at the seam so a future
    // edit that loosens one of its four conditions goes red here rather than in a browser.
    Route::middleware('web')->get('/probe-signed-subject', fn () => [
        'id' => SignedLoginAsSubject::id(),
    ])->name('probe-signed-subject');

    $this->getJson(URL::temporarySignedRoute('probe-signed-subject', now()->addMinutes(5)))
        ->assertOk()
        ->assertJson(['id' => null]);

    $this->getJson('/probe-signed-subject')->assertOk()->assertJson(['id' => null]);
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

/*
 * ── 172(a): the one-click demo sign-in, which is the SAME signed link ────────────────────────────
 *
 * Gate posture: unchanged — no `Gate::before` anywhere in this package's src/ or tests/.
 */

it('mints a signed link per demo subject, and following one authenticates the session', function () {
    seedDemo();

    // DEMO MODE ON. `beam.accounts.demo.login_links` ships false, so this line is not scaffolding —
    // it is the only reason any link exists to follow.
    config()->set('beam.accounts.demo.login_links', true);

    $links = app(DemoLoginLinks::class)->all();

    expect(array_column($links, 'key'))->toBe(BeamDemo::keys());

    foreach ($links as $link) {
        expect($link['url'])->toContain('/op/login-as')->toContain('signature=');
        // The route carries the subject's UUID/key, never the subject SLUG — the 500 in 172(a) was
        // `users/owner/op/login-as`.
        expect($link['url'])->not->toContain('/users/'.$link['key'].'/');
    }

    // End to end, as a guest, exactly as a browser follows the button.
    $owner = collect($links)->firstWhere('key', Role::Owner->value);

    $this->get($owner['url'])->assertRedirect('/');

    expect(auth()->check())->toBeTrue();
    expect(auth()->user()->email)->toBe(BeamDemo::email(Role::Owner->value));
});

it('publishes no demo links when the demo affordances are off, and none for an unseeded host', function () {
    config()->set('beam.accounts.demo.login_links', true);

    // Unseeded: the roster exists, the users do not. Buttons that cannot work are omitted rather
    // than emitted with a null url.
    expect(app(DemoLoginLinks::class)->all())->toBe([]);

    seedDemo();
    config()->set('beam.accounts.demo.enabled', false);

    expect(app(DemoLoginLinks::class)->all())->toBe([]);
    expect(app(DemoLoginLinks::class)->for(Role::Owner->value))->toBeNull();
});

/*
 * ── 172, exposure half: demo mode is a HOST-configurable gate that fails closed ─────────────────
 *
 * A published link is a bearer credential rendered into an anonymous page, so the switch that
 * publishes it defaults OFF and only a strict true opens it. These cases pin the DEFAULT and the
 * MISSING-KEY case, not merely the explicit `false` — the explicit false is the easy half, and it is
 * the absent key that a fresh host actually has.
 *
 * Gate posture: unchanged — no `Gate::before` anywhere in this package's src/ or tests/, so every
 * case below runs deny-by-default with the gate CLOSED. None of them installs one.
 */

it('publishes nothing at a fully-seeded host when the demo-mode key is ABSENT ENTIRELY', function () {
    seedDemo();

    // Not `false` — GONE. `config()->set(..., false)` would prove only that a host who wrote the
    // word false gets nothing; a fresh install has never heard of the key, and that is the case
    // that has to fail closed. `enabled()` is true here (the harness is not production), so the
    // ONLY thing standing between a guest and four bearer credentials is this key's absence.
    // Rebuilt without the key rather than `offsetUnset`/`set(null)` — the Config repository's
    // offsetUnset writes a null, which is a DIFFERENT state from absent and would have let this
    // test pass without ever exercising the missing-key path.
    $demo = config('beam.accounts.demo');
    unset($demo['login_links']);
    config()->set('beam.accounts.demo', $demo);

    expect(array_key_exists('login_links', config('beam.accounts.demo')))->toBeFalse();
    expect(config()->has('beam.accounts.demo.login_links'))->toBeFalse();

    expect(BeamDemo::enabled())->toBeTrue();
    expect(BeamDemo::publishesLoginLinks())->toBeFalse();
    expect(app(DemoLoginLinks::class)->all())->toBe([]);
});

it('ships demo mode OFF by default, so the package config alone publishes nothing', function () {
    seedDemo();

    // The merged package default, untouched by any test — `login_links => env(...) ?? false`.
    expect(config('beam.accounts.demo.login_links'))->toBeFalse();
    expect(app(DemoLoginLinks::class)->all())->toBe([]);
});

it('fails closed on a misspelled or junk demo-mode value, and opens only on a strict true', function () {
    seedDemo();

    // Anything that is not an affirmative reads as OFF. `(bool)` would make every one of these ON,
    // which is the wrong direction for a switch that publishes credentials.
    foreach ([null, '', ' ', 'ture', 'enabled', 'demo', 'off', 'no', 'false', '0', 0, []] as $junk) {
        config()->set('beam.accounts.demo.login_links', $junk);

        expect(BeamDemo::publishesLoginLinks())->toBeFalse();
        expect(app(DemoLoginLinks::class)->all())->toBe([]);
    }

    foreach ([true, 1, 'true', 'TRUE ', '1', 'on', 'yes'] as $affirmative) {
        config()->set('beam.accounts.demo.login_links', $affirmative);

        expect(BeamDemo::publishesLoginLinks())->toBeTrue();
        expect(app(DemoLoginLinks::class)->all())->not->toBe([]);
    }
});

it('keeps demo mode subordinate to the demo affordances themselves', function () {
    seedDemo();

    // A host that turned the SUBJECTS off cannot get the links back through the narrower key.
    config()->set('beam.accounts.demo.enabled', false);
    config()->set('beam.accounts.demo.login_links', true);

    expect(BeamDemo::publishesLoginLinks())->toBeFalse();
    expect(app(DemoLoginLinks::class)->all())->toBe([]);
});

it('still mints a single link for the CLI door with demo mode off, and that link still works', function () {
    seedDemo();

    // `for()` is the operator door — `splicewire:beam:accounts:login-as`, reached by someone holding
    // a shell, which outranks anything the link grants. Gating it on demo mode would have broken
    // login-as at every host in the estate to close a hole that is not on this door.
    expect(BeamDemo::publishesLoginLinks())->toBeFalse();

    $url = app(DemoLoginLinks::class)->for(Role::Owner->value);

    expect($url)->not->toBeNull();

    $this->get($url)->assertRedirect('/');
    expect(auth()->check())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// The TTL — api-surface-coherence 99. `URL::signedRoute()` mints no `expires`, and
// `hasValidSignature()` enforces one only when it is present, so an expiry-less link admits
// forever. Every mint goes through DemoLoginLinks, and DemoLoginLinks always carries one.
// ─────────────────────────────────────────────────────────────────────────────

it('always mints an expiring link, on both doors', function () {
    seedDemo();
    config()->set('beam.accounts.demo.login_links', true);

    $urls = array_column(app(DemoLoginLinks::class)->all(), 'url');
    $urls[] = app(DemoLoginLinks::class)->for(Role::Owner->value);

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $url) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($query)->toHaveKey('expires');
        expect((int) $query['expires'])->toBeGreaterThan(now()->timestamp);
    }
});

it('takes its TTL from the host config key, and falls back to the shorter default on junk', function () {
    seedDemo();

    $expiresOf = function (?string $url): int {
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        return (int) ($query['expires'] ?? 0);
    };

    // The shipped default.
    expect($expiresOf(app(DemoLoginLinks::class)->for(Role::Owner->value)))
        ->toBe(now()->addMinutes(DemoLoginLinks::DEFAULT_MINUTES)->timestamp);

    // A host raising the window for a support-escalation link.
    config()->set('beam.accounts.demo.login_link_minutes', 120);
    expect($expiresOf(app(DemoLoginLinks::class)->for(Role::Owner->value)))
        ->toBe(now()->addMinutes(120)->timestamp);

    // An explicit caller argument still wins over the host's key.
    expect($expiresOf(app(DemoLoginLinks::class)->for(Role::Owner->value, 5)))
        ->toBe(now()->addMinutes(5)->timestamp);

    // A misconfigured TTL must not 500 the login page, and must land on the SHORTER window.
    foreach ([null, 0, -10, '', 'thirty', [], false] as $junk) {
        config()->set('beam.accounts.demo.login_link_minutes', $junk);

        expect($expiresOf(app(DemoLoginLinks::class)->for(Role::Owner->value)))
            ->toBe(now()->addMinutes(DemoLoginLinks::DEFAULT_MINUTES)->timestamp);
    }
});

it('ships the TTL key in the package config', function () {
    expect(config('beam.accounts.demo.login_link_minutes'))->toBe(DemoLoginLinks::DEFAULT_MINUTES);
});
