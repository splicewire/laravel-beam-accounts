<?php

namespace Splicewire\Beam\Accounts\Routing;

use Closure;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Sharing\Sharing;
use Splicewire\Beam\Routing\ApiRoot;

/** Separate exposures: the host chooses the guest, central, session or tenant guard for each call. */
final class AccountsRoutes
{
    /** Authenticated account primitives; no authentication middleware is selected here. */
    public static function mount(): void
    {
        self::atRoot(fn () => Route::group([], dirname(__DIR__, 2).'/routes/account-api.php'));
    }

    public static function auth(): void
    {
        self::atRoot(fn () => Route::splicewireAccountsAuthV1());
    }

    public static function session(): void
    {
        self::atRoot(fn () => Route::splicewireAccountsSessionV1());
    }

    public static function sessionToken(): void
    {
        self::atRoot(fn () => Route::splicewireAccountsSessionTokenV1());
    }

    public static function me(): void
    {
        self::atRoot(fn () => Route::splicewireAccountsMeV1());
    }

    /** Optional sharing-ledger reads; the host supplies its authenticated boundary. */
    public static function sharingLedger(): void
    {
        self::atRoot(fn () => Sharing::ledgerResources(['groupPrefix' => '', 'middleware' => []]));
    }

    private static function atRoot(Closure $routes): void
    {
        ApiRoot::mount(app(Router::class), 'beam.accounts.api_root', 'accounts', function (Router $router) use ($routes): void {
            $router->name('beam.accounts.')->group($routes);
        });
    }
}
