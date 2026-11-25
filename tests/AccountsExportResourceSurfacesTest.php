<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Routing\AccountsRoutes;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Http\Particle\ParticleController;

/*
 * owner-review-20261112 56: the accounts export carries the resource sub-surfaces the flagship's legacy tenant mount
 * has, so every legacy row has a twin at the owner-tier root. The tokens index stamps `tokens` with its hook-event
 * catalog, and the invitations index stamps `invitations`. Each stamp gets its per-mount discovery listing from beam's
 * boot-time pass, which this suite runs by hand because it mounts after boot, as a host's route file does.
 */
beforeEach(function (): void {
    $this->app->register(Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);

    // The flagship's shape: the legacy spelling beside the exports, each in its own host boundary.
    Route::prefix('api/v1')->middleware('host')->group(fn () => Route::splicewireAccountApiRoutes(prefix: 'beam/accounts', middleware: []));
    Route::middleware('host-tenant')->group(fn () => AccountsRoutes::me());
    Route::middleware('host-account')->group(fn () => AccountsRoutes::mount());
    app(ResourceDiscoveryAutoMounter::class)->mount();
    Route::getRoutes()->refreshNameLookups();
});

it('mounts discovery and the hook-event catalog at the export root, inside the host boundary', function (string $uri, string $name, string $resource, string $middleware) {
    $route = app('router')->getRoutes()->match(Request::create('/'.$uri));

    expect($route->uri())->toBe($uri)
        ->and($route->getName())->toBe($name)
        ->and($route->defaults[ParticleController::RESOURCE] ?? null)->toBe($resource)
        // A sub-surface outside the boundary would be an unauthenticated door onto an authenticated resource.
        ->and($route->gatherMiddleware())->toContain($middleware);
})->with([
    'tokens discovery' => ['beam/accounts/tokens/discovery', 'beam.accounts.api.tokens.discovery', 'tokens', 'host-account'],
    'tokens hook events' => ['beam/accounts/tokens/hooks/events', 'beam.accounts.api.tokens.hooks.events', 'tokens', 'host-account'],
    'invitations discovery' => ['beam/accounts/invitations/discovery', 'beam.accounts.api.invitations.discovery', 'invitations', 'host-account'],
    'me discovery' => ['beam/accounts/me/discovery', 'beam.accounts.api.me.discovery', 'me', 'host-tenant'],
]);

it('stamps the two indexes it stamps and leaves the rest of the export unchanged', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('beam.accounts.api.tokens.index')->defaults[ParticleController::RESOURCE] ?? null)->toBe('tokens')
        ->and($routes->getByName('beam.accounts.api.invitations.index')->defaults[ParticleController::RESOURCE] ?? null)->toBe('invitations')
        ->and($routes->getByName('beam.accounts.api.tokens.store')->defaults)->not->toHaveKey(ParticleController::RESOURCE)
        ->and($routes->getByName('beam.accounts.api.invitations.resend')->getActionName())->toContain('TeamInvitationController@resend');
});

// `route:cache` serialization of these rows is RouteNameUniquenessTest's, which boots the way the hosts do.
it('names every account row once beside the legacy spelling, the export rows on the .api. stem', function () {
    $routes = app('router')->getRoutes();
    // The account stems only: this boot also auto-registers the package's Fortify-side routes, whose own uniqueness
    // RouteNameUniquenessTest owns (with `register_routes` off).
    $names = collect($routes->getRoutes())->map(fn ($route) => (string) $route->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'beam.accounts.'));

    expect($names->duplicates()->values()->all())->toBe([])
        ->and(collect($routes->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'beam/accounts/'))
            ->map(fn ($route) => (string) $route->getName())
            ->reject(fn (string $name) => str_starts_with($name, 'beam.accounts.api.'))
            ->values()->all())->toBe([]);
});
