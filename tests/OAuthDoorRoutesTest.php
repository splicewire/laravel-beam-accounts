<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Doors\AccountDoors;
use Splicewire\Beam\Accounts\Doors\Door;
use Splicewire\Beam\Accounts\Doors\DoorClosed;
use Splicewire\Beam\Accounts\Doors\NewUserData;
use Splicewire\Beam\Accounts\Http\Controllers\OAuthController;

/*
 * purchase-walkthrough BUY-05 (M10): OAuth sign-in is beam-accounts' inert macro, `splicewireAccountsOAuth`, over
 * AccountDoors. It mounts a route only for a DECLARED provider (a closed door registers no route), and creating an
 * account through the OAuth door needs the provider to have verified the email (PF-02's rule, carried into the door).
 */

/**
 * Socialite is the HOST's install, so this package's tests mock its facade, through a stand-in when the real package is
 * absent (laravel/socialite's Factory contract tells which). The stand-in's root is bound per test, since each test boots
 * a fresh application.
 */
function fakeSocialite(): void
{
    if (interface_exists(Laravel\Socialite\Contracts\Factory::class)) {
        return;
    }
    require_once __DIR__.'/Support/socialite-facade-stub.php';
    app()->instance('beam-accounts.test.socialite', new stdClass);
}

function mountOAuth(): void
{
    Route::splicewireAccountsOAuth();
    app('router')->getRoutes()->refreshNameLookups();
}

it('mounts nothing while no OAuth provider is declared', function () {
    config(['beam.accounts.doors.oauth.providers' => []]);
    mountOAuth();

    expect(Route::has('oauth.redirect'))->toBeFalse()
        ->and(Route::has('oauth.callback'))->toBeFalse();
});

it('mounts the redirect and callback for declared providers only', function () {
    config(['beam.accounts.doors.oauth.providers' => ['google']]);
    mountOAuth();

    $callback = app('router')->getRoutes()->getByName('oauth.callback');

    expect($callback->uri())->toBe('auth/{provider}/callback')
        ->and($callback->getAction('uses'))->toBe(OAuthController::class.'@handleCallback')
        ->and($callback->wheres['provider'] ?? null)->toBe('google');
});

it('creates through the OAuth door only for a verified email on an admitted domain', function () {
    config(['beam.accounts.doors.oauth' => ['providers' => ['google'], 'create' => 'domains', 'domains' => ['acme.test']]]);
    $doors = app(AccountDoors::class);

    expect(fn () => $doors->create(Door::OAuth, new NewUserData('Ada', 'ada@acme.test', password: 'x', verified: false)))->toThrow(DoorClosed::class);
    expect($doors->create(Door::OAuth, new NewUserData('Ada', 'ada@acme.test', password: 'x', verified: true))->email)->toBe('ada@acme.test');
});

/**
 * ux-walkthrough UX-13 (build.qa, review-r1 HIGH): the Google door keeps the signed-out visit's deep link. The redirect
 * stores a SAFE `?intended=` (Landing::safeIntended, the resolver's own rule) as `url.intended`, which the callback
 * already lands through; anything the resolver refuses is not stored.
 */
it('keeps a safe intended path across the OAuth redirect, and nothing it refuses', function () {
    config(['beam.accounts.doors.oauth.providers' => ['google']]);
    // As a host mounts it: inside the `web` group, which carries the session the redirect writes to.
    Route::middleware('web')->group(fn () => Route::splicewireAccountsOAuth());
    app('router')->getRoutes()->refreshNameLookups();
    fakeSocialite();
    Laravel\Socialite\Facades\Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.example/o/oauth2'));

    $this->get('/auth/google/redirect?intended='.urlencode('/ui/studio/abc?tab=cells'))->assertRedirect();
    expect(session('url.intended'))->toBe('/ui/studio/abc?tab=cells');

    // In sequence, with no clearing in between (build.qa): a later redirect with a refused target, or none, must not
    // inherit the earlier safe one.
    foreach (['//evil.example/x', 'https://evil.example/x', '/logout', null] as $next) {
        $this->get('/auth/google/redirect?intended='.urlencode('/ui/studio/abc'))->assertRedirect();
        expect(session('url.intended'))->toBe('/ui/studio/abc');
        $this->get('/auth/google/redirect'.($next === null ? '' : '?intended='.urlencode($next)))->assertRedirect();
        expect(session('url.intended'))->toBeNull();
    }
});

/**
 * build.qa (UX-13): the Google door sends the COMPOSED target, the picker carrying the deep link
 * (`/ui/tenants?intended=<path>`), because the callback lands through the resolver on the callback host and never
 * passes the SPA's own login step. One flow: the redirect keeps it, the callback lands on it, and the carried path
 * survives the resolver's decoding.
 */
it('lands the Google callback on the composed picker target, the deep link still carried', function () {
    config(['beam.accounts.doors.oauth.providers' => ['google']]);
    Route::middleware('web')->group(fn () => Route::splicewireAccountsOAuth());
    app('router')->getRoutes()->refreshNameLookups();
    fakeSocialite();

    $model = Splicewire\Beam\Accounts\Facades\BeamAccounts::userModel();
    $user = $model::query()->forceCreate(['name' => 'Ada', 'email' => 'ada@acme.test', 'password' => bcrypt('x'), 'google_id' => 'g-1']);
    $composed = '/ui/tenants?intended='.rawurlencode('/studio/abc?tab=cells');

    Laravel\Socialite\Facades\Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.example/o/oauth2'));
    $this->get('/auth/google/redirect?intended='.rawurlencode($composed))->assertRedirect();

    Laravel\Socialite\Facades\Socialite::shouldReceive('driver->user')->andReturn(new class
    {
        public string $id = 'g-1';

        public string $email = 'ada@acme.test';

        public string $name = 'Ada';

        public function getRaw(): array
        {
            return ['email_verified' => true];
        }
    });
    $landed = $this->get('/auth/google/callback')->headers->get('Location');

    expect(auth()->id())->toBe($user->getKey());
    parse_str((string) parse_url((string) $landed, PHP_URL_QUERY), $query);
    expect(parse_url((string) $landed, PHP_URL_PATH))->toBe('/ui/tenants')
        ->and($query['intended'] ?? null)->toBe('/studio/abc?tab=cells');
});
