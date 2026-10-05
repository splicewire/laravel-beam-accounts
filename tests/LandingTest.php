<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Landing;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * ux-walkthrough UX-11 (IA-5, M3): ONE landing resolver for every sign-in door. Precedence: (1) a SAFE intended URL,
 * (2) a seat → the tenant home, (3) os.operate with no seat → the operator home, (4) otherwise the tenant home.
 * OQ-1 (owner, 2026-10-05): a seated operator lands in App. An unmounted home falls back to '/'.
 * The intended check is by RESOLUTION, after decoding (review-r1 and the lead, from today's A2 open redirect): control
 * characters are refused, and only a same-origin URL survives; the redirect is the normalised result, never the input.
 */

beforeEach(function () {
    Route::get('dashboard', fn () => 'app')->name('dashboard');
    Route::get('operator', fn () => 'operator')->name('operator.home');
    Route::get('settings/team', fn () => 'team')->name('account.team');
    Route::getRoutes()->refreshNameLookups();
    $this->operators = [];
    Gate::define('entitlement:os.operate', fn ($user) => in_array($user->getKey(), $this->operators, true));
});

function landingUser(string $email): User
{
    return User::create(['name' => 'U', 'email' => $email, 'password' => 'x']);
}

function seatFor(User $user): void
{
    $owner = landingUser('owner-'.uniqid().'@acme.test');
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => false]);
    Membership::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => Role::Member->value]);
}

it('lands a user with a seat in the app', function () {
    $user = landingUser('seat@acme.test');
    seatFor($user);

    expect(Landing::for($user))->toBe('/dashboard');
});

it('lands an operator with no seat in Operator', function () {
    $user = landingUser('op@acme.test');
    $this->operators[] = $user->getKey();

    expect(Landing::for($user))->toBe('/operator');
});

it('lands an owner who is also the operator in the App (OQ-1)', function () {
    $user = landingUser('owner-op@acme.test');
    seatFor($user);
    $this->operators[] = $user->getKey();

    expect(Landing::for($user))->toBe('/dashboard');
});

it('lands anyone else on the tenant home', function () {
    expect(Landing::for(landingUser('nobody@acme.test')))->toBe('/dashboard');
});

it('falls back to / when the home is not mounted', function () {
    $user = landingUser('op2@acme.test');
    $this->operators[] = $user->getKey();
    config(['beam.core.realms.operator.home' => 'nowhere.home']);

    expect(Landing::for($user))->toBe('/');
});

it('honours a safe intended URL first, as its normalised path', function (string $intended, string $expected) {
    expect(Landing::for(landingUser('i-'.uniqid().'@acme.test'), $intended))->toBe($expected);
})->with([
    'a deep link' => ['/reports/42?tab=usage#top', '/reports/42?tab=usage#top'],
    'the same origin, absolute' => ['http://localhost/reports/42', '/reports/42'],
    // review-r1 on 2c106e1: judged decoded, returned exactly as sent, so an encoded value keeps its meaning.
    'an encoded query value' => ['/search?q=a%26b#x', '/search?q=a%26b#x'],
    'an encoded path' => ['/files/a%20b%3Fc', '/files/a%20b%3Fc'],
    // build.qa on fc2d3ef: parse_url() mangles raw non-ASCII bytes; they are percent-encoded instead, same meaning.
    'a raw non-ASCII path' => ["/caf\u{e9}/\u{3000}x", '/caf%C3%A9/%E3%80%80x'],
]);

it('refuses an unsafe intended URL and lands on the role home', function (string $intended) {
    expect(Landing::for(landingUser('u-'.uniqid().'@acme.test'), $intended))->toBe('/dashboard');
})->with([
    'protocol-relative' => ['//evil.com'],
    'encoded protocol-relative' => ['/%2F%2Fevil.com'],
    'backslash' => ['/\\evil.com'],
    'tab' => ["/\t/evil.com"],
    'encoded tab' => ['/%09/evil.com'],
    'encoded LF' => ['/%0a/evil.com'],
    'encoded CR' => ['/%0d/evil.com'],
    'scheme without slashes' => ['https:evil.com'],
    'another host' => ['https://evil.com/x'],
    'a look-alike host' => ['http://localhost.evil.com/x'],
    'javascript' => ['javascript:alert(1)'],
    'logout' => ['/logout'],
    'encoded logout' => ['/%6Cogout'],
    'login' => ['/login'],
    'an api path' => ['/api/v1/me'],
    'a frame manifest' => ['/api/operator/frame/manifest'],
    'json' => ['/reports/42.json'],
    'a dot-dot walk into a refused path' => ['/%2e%2e/api/v1/me'],
    // '/' percent-encoded six times over: still changing after five decoding rounds.
    'still decoding after five rounds' => ['/%'.str_repeat('25', 6).'2F/evil.com'],
]);

it('leaves fortify.home holding no literal path once the doors land through Landing (T3)', function () {
    expect(config('fortify.home'))->toBeNull();
});
