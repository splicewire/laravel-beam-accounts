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

            // ux-walkthrough UX-11 (IA-5, T3): every door lands through Landing::for(), so `fortify.home` decides nothing;
            // it holds no literal path that could quietly start deciding again. Fortify's remaining readers carry their
            // own defaults (password reset → login, logout → '/').
            config(['fortify.home' => null]);
        });
    }

    /**
     * `GET /logout` → a confirm page that POSTs (ux-walkthrough UX-11, IA-5). Registered at package boot, so it precedes
     * a host's route files and their catch-alls; a host that declares its own `GET /logout` replaces it by registering
     * later (the flagship sends it to its SPA's confirm). `beam.accounts.logout_confirm` = false opts out.
     */
    #[Chained('boot', order: 180)]
    protected function bootLogoutConfirm(): void
    {
        if (! config('beam.accounts.logout_confirm', true)) {
            return;
        }

        \Illuminate\Support\Facades\Route::middleware('web')
            ->get('logout', \Splicewire\Beam\Accounts\Http\Controllers\LogoutConfirmController::class)
            ->name('logout.confirm');
    }

    /**
     * Every Fortify and passkey sign-in door lands through Landing::for() (ux-walkthrough UX-11, IA-5), whether or not
     * this package bootstraps Fortify's actions: where a door lands is not part of a host's own auth stack (the flagship
     * keeps `bootstrap_fortify` off and still serves Fortify's web login).
     */
    #[Chained('boot', order: 190)]
    protected function bootLandingResponses(): void
    {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginResponse::class, \Splicewire\Beam\Accounts\Fortify\Responses\LandingLoginResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\TwoFactorLoginResponse::class, \Splicewire\Beam\Accounts\Fortify\Responses\LandingTwoFactorLoginResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\RegisterResponse::class, \Splicewire\Beam\Accounts\Fortify\Responses\LandingRegisterResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\VerifyEmailResponse::class, \Splicewire\Beam\Accounts\Fortify\Responses\LandingVerifyEmailResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\PasswordConfirmedResponse::class, \Splicewire\Beam\Accounts\Fortify\Responses\LandingPasswordConfirmedResponse::class);
        $this->app->singleton(\Laravel\Passkeys\Contracts\PasskeyLoginResponse::class, \Splicewire\Beam\Accounts\Fortify\Responses\LandingPasskeyLoginResponse::class);
        // Fortify's controllers build this one by class with a `name` (email-verification prompt and resend).
        $this->app->bind(
            \Laravel\Fortify\Http\Responses\RedirectAsIntended::class,
            fn ($app, array $parameters) => new \Splicewire\Beam\Accounts\Fortify\Responses\LandingRedirectAsIntended((string) ($parameters['name'] ?? '')),
        );
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
