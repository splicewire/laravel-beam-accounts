<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresRouteMacro
{
    /**
     * Register the settings surface under a macro so a host can mount it wherever
     * it likes; the default boot mounts it for you unless `register_routes` is off.
     */
    #[Chained('boot', order: 30)]
    protected function bootRouteMacro(): void
    {
        Route::macro('splicewireAccountRoutes', function () {
            $config = config('beam.accounts.routes');

            Route::prefix($config['prefix'] ?? 'settings')
                ->middleware($config['middleware'] ?? ['web', 'auth'])
                // ⚠️ `dirname(__DIR__, 2)`, not `__DIR__.'/..'`: this lives in src/Concerns/, one level deeper
                // than the provider it was extracted from, so a `__DIR__`-relative path silently resolves
                // one directory short. php -l passes and only a runtime read fails.
                ->group(dirname(__DIR__, 2).'/routes/account.php');
        });

        /**
         * The account-tier REST survivors — tokens (reveal-once mint + lifecycle), members
         * (role change + remove) and invitations (send/resend/revoke).
         *
         * A MACRO ONLY — unlike `splicewireAccountRoutes()` above, nothing in this package calls it.
         * `routes/account-api.php`'s header carries the two reasons; the short one is that
         * `~/Herd/splicewire-app` already mounts this family under the same route NAMES from its own
         * route file, and a package that registered them unconditionally would decide by provider
         * order which one that host serves.
         *
         * The host supplies the prefix and the middleware, because both are facts about an exposure
         * that mints bearer credentials. Defaults reproduce the flagship's own mount
         * (`beam/accounts`, session `web` + `auth`), so a starter can call it bare.
         */
        Route::macro('splicewireAccountApiRoutes', function (?string $prefix = null, ?array $middleware = null) {
            $prefix ??= (string) config('beam.accounts.api_root', 'beam/accounts');
            $middleware ??= (array) config('beam.accounts.routes.middleware', ['web', 'auth']);

            Route::prefix($prefix)
                ->name('beam.accounts.')
                ->middleware($middleware)
                // `dirname(__DIR__, 2)` for the same reason as above — this trait lives one level
                // deeper than the provider the macro was extracted from.
                ->group(dirname(__DIR__, 2).'/routes/account-api.php');
        });
    }
}
