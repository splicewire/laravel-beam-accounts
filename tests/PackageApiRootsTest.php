<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Routing\AccountsRoutes;

beforeEach(function (): void {
    $this->app->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);
});

it('mounts each account capability at its default root within its own host boundary', function () {
    Route::prefix('legacy')->name('legacy.')->group(fn () => Route::splicewireAccountsAuthV1());
    Route::middleware('host-guest')->group(fn () => AccountsRoutes::auth());
    Route::middleware('host-central')->group(fn () => AccountsRoutes::session());
    Route::middleware('host-session')->group(fn () => AccountsRoutes::sessionToken());
    Route::middleware('host-tenant')->group(fn () => AccountsRoutes::me());
    Route::middleware('host-account')->group(fn () => AccountsRoutes::mount());
    $routes = app('router')->getRoutes();
    $login = $routes->match(Request::create('/beam/accounts/login', 'POST'));
    expect($login->getActionName())->toBe($routes->match(Request::create('/legacy/login', 'POST'))->getActionName())
        ->and($login->gatherMiddleware())->toContain('host-guest', 'throttle:login')->not->toContain('host-central', 'host-session');
    foreach (['logout' => 'host-central', 'session-token' => 'host-session', 'me' => 'host-tenant', 'tokens' => 'host-account'] as $path => $middleware) {
        $method = in_array($path, ['logout', 'session-token'], true) ? 'POST' : 'GET';
        expect($routes->match(Request::create('/beam/accounts/'.$path, $method))->gatherMiddleware())->toContain($middleware);
    }
});

it('honors a package-root override and preserves legacy API macros', function () {
    config(['beam.accounts.api_root' => '/custom/accounts/']);
    Route::prefix('legacy')->name('legacy.')->group(fn () => Route::splicewireAccountsMeV1());
    AccountsRoutes::me();
    $routes = app('router')->getRoutes();
    expect($routes->match(Request::create('/custom/accounts/me'))->getActionName())
        ->toBe($routes->match(Request::create('/legacy/me'))->getActionName());
});

it('keeps the sharing ledgers optional and index only at both roots', function () {
    AccountsRoutes::mount();
    $routes = app('router')->getRoutes();
    expect(collect($routes->getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'access-grants')))->toBeFalse();
    Route::prefix('legacy')->name('legacy.')->group(fn () => Splicewire\Beam\Accounts\Sharing\Sharing::ledgerResources());
    Route::middleware('host-account')->group(fn () => AccountsRoutes::sharingLedger());
    foreach (['access-grants', 'view-requests'] as $key) {
        $new = $routes->match(Request::create('/beam/accounts/'.$key));
        $old = $routes->match(Request::create('/legacy/resources/'.$key));
        expect($new->getActionName())->toBe($old->getActionName())
            ->and($new->gatherMiddleware())->toContain('host-account');
        expect(collect($routes->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'beam/accounts/'.$key) && ! str_ends_with($route->uri(), '/hooks/events'))->count())->toBe(1);
    }
});

it('preserves the explicit legacy account prefix and middleware used by the tower host', function () {
    Route::prefix('v1')->middleware('host')->group(fn () => Route::splicewireAccountApiRoutes(prefix: 'beam/accounts', middleware: []));
    $route = app('router')->getRoutes()->match(Request::create('/v1/beam/accounts/tokens'));
    expect($route->getName())->toBe('beam.accounts.tokens.index')
        ->and($route->gatherMiddleware())->toContain('host')->not->toContain('web', 'auth');
});
