<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Routing\AccountsRoutes;

/**
 * Every route name stays unique when a host mounts all of this package's routes the way the real hosts do, so
 * `route:cache` can serialize them (ticket 65).
 *
 * The V1 macros register bare names (`login`, `logout`, `password.email`, `password.update`) that a Fortify host
 * also defines. Root ruled them intended (Q65-1, 2026-11-25): a host mounts them inside a name group, as the
 * flagship (`RouteServiceProvider`, `->name('api.v1.')`) and the tower starter (`routes/api.php`) both do, and
 * ticket 16 removes them.
 *
 * Both hosts also set `beam.accounts.register_routes` false, so this class boots that way. The rest of the suite
 * keeps the shipped default on, and the last case pins what that costs beside Fortify ({@see README.md}).
 */
class RouteNameUniquenessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.accounts.register_routes', false);
    }

    public function test_a_host_shaped_mount_of_every_package_route_names_each_route_once(): void
    {
        $this->app->register(\Schemastud\DataSchemas\LaravelDataSchemasServiceProvider::class);

        // The V1 macros, the way both hosts mount them.
        Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
            Route::splicewireAccountsAuthV1();
            Route::splicewireAccountsSessionV1();
            Route::splicewireAccountsSessionTokenV1();
            Route::splicewireAccountsMeV1();
        });
        // The flagship's legacy account API (13c amend) beside the new exports.
        Route::prefix('api/v1')->middleware('host')->group(fn () => Route::splicewireAccountApiRoutes(prefix: 'beam/accounts', middleware: []));
        AccountsRoutes::auth();
        AccountsRoutes::session();
        AccountsRoutes::sessionToken();
        AccountsRoutes::me();
        AccountsRoutes::mount();
        AccountsRoutes::sharingLedger();
        // The per-mount discovery and hook-event rows the resource stamps add (owner-review-20261112 56), as beam's
        // boot-time pass mounts them at a host.
        app(\Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter::class)->mount();

        $routes = app('router')->getRoutes();
        $names = collect($routes->getRoutes())->map(fn ($route) => $route->getName())->filter();

        // ALL names: the package's, Fortify's and the test app's own.
        $this->assertSame([], $names->duplicates()->values()->all());
        $this->assertTrue($names->contains('api.v1.login') && $names->contains('api.v1.logout'));
        $this->assertTrue($names->contains('beam.accounts.api.login'));

        // The step `route:cache` throws at on a duplicate name (AbstractRouteCollection::addToSymfonyRoutesCollection).
        $this->assertGreaterThan(0, $routes->toSymfonyRouteCollection()->count());
    }

    public function test_the_shipped_register_routes_default_beside_fortify_duplicates_exactly_user_password_update(): void
    {
        // The residual, pinned so it cannot grow unnoticed: the settings routes (on by default) and Fortify's
        // `Features::updatePasswords()` both name `user-password.update`. The host's fix is
        // `ACCOUNT_REGISTER_ROUTES=false`; the default stays (Q65-1).
        $this->app['config']->set('beam.accounts.register_routes', true);
        Route::splicewireAccountRoutes();
        app('router')->getRoutes()->refreshNameLookups();

        $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route) => $route->getName())->filter();

        $this->assertSame(['user-password.update'], $names->duplicates()->values()->all());
    }
}
