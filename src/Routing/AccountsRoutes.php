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
    // Expand-only stem: ticket16 returns to beam.accounts. after legacy mounts are removed.
    private const NAME_PREFIX = 'beam.accounts.api.';

    // The legacy names the bare splicewireAccountApiRoutes() macro keeps (TokensEndpoints and its clients read them).
    private const LEGACY_NAME_PREFIX = 'beam.accounts.';

    /** Authenticated account primitives; no authentication middleware is selected here. */
    public static function mount(): void
    {
        self::atRoot(fn () => Route::group([], dirname(__DIR__, 2).'/routes/account-api.php'));
    }

    /**
     * The bare legacy `Route::splicewireAccountApiRoutes()` exposure: the same routes at the same root, mounted through
     * ApiRoot (catch-all ordering kept), under the legacy `beam.accounts.*` names. Removed with the legacy mounts (ticket16).
     *
     * @internal called only by the macro
     */
    public static function mountLegacy(): void
    {
        self::atRoot(fn () => Route::group([], dirname(__DIR__, 2).'/routes/account-api.php'), self::LEGACY_NAME_PREFIX);
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

    private static function atRoot(Closure $routes, string $namePrefix = self::NAME_PREFIX): void
    {
        ApiRoot::mount(app(Router::class), 'beam.accounts.api_root', 'accounts', function (Router $router) use ($routes, $namePrefix): void {
            $router->name($namePrefix)->group($routes);
        });
    }
}
