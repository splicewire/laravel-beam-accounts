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
