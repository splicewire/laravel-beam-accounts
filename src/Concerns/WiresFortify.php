<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Doors\AccountDoors;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Fortify\ResetUserPassword;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresFortify
{
    /**
     * Wire Fortify as the engine's default auth substrate: the registration + password-reset
     * actions and the login/two-factor rate limiters. Session/Fortify, never Passport — an
     * overridable seam (a satellite may rebind the Fortify actions), not a per-satellite fork.
     */
    /**
     * Fortify's registration feature follows the declared door (purchase-walkthrough M10), whatever a host's
     * config/fortify.php lists: a closed door mounts no registration route. Called from the REGISTER phase so the
     * `booting` callback runs after every provider merged its config and before any provider (Fortify's routes
     * included) boots. Runs whether or not this package bootstraps Fortify's actions.
     */
    protected function registerFortifyFeaturesFromDoors(): void
    {
        $this->app->booting(function (): void {
            config(['fortify.features' => $this->app->make(AccountDoors::class)->fortifyFeatures((array) config('fortify.features', []))]);
        });
    }

    #[Chained('boot', order: 50)]
    protected function bootFortify(): void
    {
        if (! config('beam.accounts.bootstrap_fortify', true)) {
            return;
        }

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower($request->input(Fortify::username()).'|'.$request->ip()));

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }
}
