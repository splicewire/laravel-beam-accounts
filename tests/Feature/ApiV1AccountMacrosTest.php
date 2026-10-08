<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\RouteVisibility;

/*
 * The accounts api/v1 surface ships as three INERT macros (tower-is-splicewire D5′): no prefix, no name prefix and no
 * guard of their own, so a host places each one inside its own group (guest central, signed-in central, tenant). The
 * package mounts none of them.
 */

function mountInHost(string $macro, array $middleware): void
{
    Route::prefix('api/v1')->name('api.v1.')->middleware($middleware)->group(fn () => Route::$macro());
    Route::getRoutes()->refreshNameLookups();
}

function routeNamed(string $name): Illuminate\Routing\Route
{
    $route = Route::getRoutes()->getByName($name);
    expect($route)->not->toBeNull("route [{$name}] is not mounted");

    return $route;
}

it('mounts nothing by itself', function () {
    expect(Route::has('api.v1.login'))->toBeFalse()
        ->and(Route::has('api.v1.logout'))->toBeFalse()
        ->and(Route::has('api.v1.me'))->toBeFalse();
});

it('takes the host\'s prefix, names and middleware for the guest auth routes, and declares login Public', function () {
    mountInHost('splicewireAccountsAuthV1', ['host-guest']);

    $login = routeNamed('api.v1.login');
    expect($login->uri())->toBe('api/v1/login')
        ->and($login->gatherMiddleware())->toContain('host-guest', 'throttle:login')
        ->and(BeamRouteAction::visibility($login))->toBe(RouteVisibility::Public);
    foreach (['api.v1.password.email', 'api.v1.password.update', 'api.v1.passkey.login-options', 'api.v1.passkey.login'] as $name) {
        expect(routeNamed($name)->gatherMiddleware())->toContain('host-guest');
    }
});

it('places logout and passkey management in the host\'s signed-in group', function () {
    mountInHost('splicewireAccountsSessionV1', ['auth:sanctum']);

    expect(routeNamed('api.v1.logout')->uri())->toBe('api/v1/logout')
        ->and(routeNamed('api.v1.logout')->gatherMiddleware())->toContain('auth:sanctum');
    foreach (['api.v1.passkeys.index', 'api.v1.passkeys.registration-options', 'api.v1.passkeys.store', 'api.v1.passkeys.update', 'api.v1.passkeys.destroy'] as $name) {
        expect(routeNamed($name)->gatherMiddleware())->toContain('auth:sanctum');
    }
});

it('lets a host mount the interim session to bearer mint behind its own web guard', function () {
    mountInHost('splicewireAccountsSessionTokenV1', ['web', 'auth']);

    expect(routeNamed('api.v1.session-token.store')->uri())->toBe('api/v1/session-token')
        ->and(routeNamed('api.v1.session-token.store')->gatherMiddleware())->toContain('web', 'auth');
});

it('places me in the host\'s tenant group and declares it Public', function () {
    // inResource('me') builds MeController at registration, which needs the host's schema registry; a host binds one.
    $directory = sys_get_temp_dir().'/beam-accounts-api-v1-macros';
    @mkdir($directory, 0777, true);
    $this->app->instance(SchemaRegistry::class, new FilesystemSchemaRegistry($directory));

    mountInHost('splicewireAccountsMeV1', ['host-tenant']);

    expect(routeNamed('api.v1.me')->gatherMiddleware())->toContain('host-tenant')
        ->and(BeamRouteAction::visibility(routeNamed('api.v1.me')))->toBe(RouteVisibility::Public)
        ->and(routeNamed('api.v1.me.update')->uri())->toBe('api/v1/me');
});

it('keeps a host\'s own named limiter and defines only the missing ones', function () {
    RateLimiter::for('login', fn () => Limit::perMinute(1));

    mountInHost('splicewireAccountsAuthV1', []);

    // limiter() hands back a fresh closure on every call, so the check is what the limiter PRODUCES: still the host's
    // one-per-minute, not the package default.
    $produced = (RateLimiter::limiter('login'))(Request::create('/api/v1/login', 'POST', ['email' => 'a@b.test']));
    $limits = $produced instanceof Limit ? [$produced] : $produced;
    expect(collect($limits)->map(fn (Limit $limit) => $limit->maxAttempts)->all())->toBe([1])
        ->and(RateLimiter::limiter('password-reset'))->not->toBeNull()
        ->and(RateLimiter::limiter('passkey'))->not->toBeNull();
});
