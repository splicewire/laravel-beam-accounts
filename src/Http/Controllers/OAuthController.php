<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Splicewire\Beam\Accounts\Doors\AccountDoors;
use Splicewire\Beam\Accounts\Doors\Door;
use Splicewire\Beam\Accounts\Doors\DoorClosed;
use Splicewire\Beam\Accounts\Doors\NewUserData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;

/**
 * OAuth sign-in through the account doors (purchase-walkthrough M10, rule BUY-9), mounted by the inert
 * `Route::splicewireAccountsOAuth()` macro for the providers `beam.accounts.doors.oauth.providers` declares. Moved here
 * from the flagship with purchase fix 02's rules intact:
 *
 *  - an account already linked by provider id signs in;
 *  - one matched by email signs in only when the provider says the email is verified, or anyone could claim it with a
 *    provider account at that address;
 *  - a NEW account is created only through `AccountDoors::create(Door::OAuth, …)`: an exact, declared domain and a
 *    verified email, read from config, never env();
 *  - every refusal and error returns to the sign-in page (`beam.accounts.doors.oauth.sign_in`, else the named
 *    `login`) with an `error` code;
 *  - it signs in by SESSION only. There is no popup "opener" branch: one minted a bearer token for any request
 *    that asked (`?opener=1`) and posted it to `window.opener` with target "*" (security row e5684baf). It also
 *    never goes stateless(): the OAuth state is Socialite's, and it is checked.
 *
 * Needs `laravel/socialite`, which the host installs.
 */
class OAuthController extends Controller
{
    /** Socialite sets and stores its own OAuth state; nothing a request carries may replace it. */
    public function handleRedirect(string $provider)
    {
        return Socialite::driver($provider)->redirect();
    }

    public function handleCallback(AccountDoors $doors, string $provider)
    {
        try {
            // NOT stateless(): Socialite checks the state it stored at the redirect, so a forged callback cannot sign a
            // victim into someone else's linked account (login CSRF).
            $oauthUser = Socialite::driver($provider)->user();
            $providerColumn = "{$provider}_id";
            $verified = Arr::get($oauthUser->getRaw(), 'email_verified') === true;
            $model = BeamAccounts::userModel();

            $user = $model::query()->where($providerColumn, $oauthUser->id)->first();

            if (! $user && ($byEmail = $model::query()->where('email', $oauthUser->email)->first())) {
                if (! $verified) {
                    return $this->toSignIn('oauth_email_unverified');
                }
                $user = $byEmail;
            }

            if (! $user) {
                if (! $doors->admit(Door::OAuth, (string) $oauthUser->email)->admitted) {
                    return $this->toSignIn('oauth_registration_closed');
                }
                if (! $verified) {
                    return $this->toSignIn('oauth_email_unverified');
                }

                $user = $doors->create(Door::OAuth, new NewUserData(
                    name: (string) $oauthUser->name,
                    email: (string) $oauthUser->email,
                    verified: true,
                ));
                $user->forceFill([$providerColumn => $oauthUser->id])->save();
            }

            Auth::login($user);

            return redirect('/');
        } catch (DoorClosed) {
            return $this->toSignIn('oauth_registration_closed');
        } catch (Exception $e) {
            report($e);

            return $this->toSignIn('oauth_failed');
        }
    }

    private function toSignIn(string $error): RedirectResponse
    {
        $page = config('beam.accounts.doors.oauth.sign_in') ?? (Route::has('login') ? route('login', [], false) : '/login');

        return redirect($page.(str_contains($page, '?') ? '&' : '?').'error='.$error);
    }
}
