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
    if (! class_exists(\Laravel\Socialite\Facades\Socialite::class)) {
        require_once __DIR__.'/Support/socialite-facade-stub.php';
        app()->instance('beam-accounts.test.socialite', new stdClass);
    }
    \Laravel\Socialite\Facades\Socialite::shouldReceive('driver->redirect')->andReturn(redirect('https://accounts.example/o/oauth2'));

    $this->get('/auth/google/redirect?intended='.urlencode('/ui/studio/abc?tab=cells'))->assertRedirect();
    expect(session('url.intended'))->toBe('/ui/studio/abc?tab=cells');

    foreach (['//evil.example/x', 'https://evil.example/x', '/logout'] as $hostile) {
        session()->forget('url.intended');
        $this->get('/auth/google/redirect?intended='.urlencode($hostile))->assertRedirect();
        expect(session('url.intended'))->toBeNull();
    }
});
