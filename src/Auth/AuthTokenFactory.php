<?php

namespace Splicewire\Beam\Accounts\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\NewAccessToken;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;

/**
 * The single place a first-party session/login token is minted.
 *
 * Both the password/OAuth path (AuthUserResource) and the passkey path
 * (PasskeyLoginController) mint the same kind of token — named after the
 * User-Agent, stamped with a provenance, and given a remember-me lifetime. Homing
 * that here keeps the expiry windows + provenance stamping from drifting apart
 * across the two call sites (login-branding-passkey tickets 06 + 09).
 */
class AuthTokenFactory
{
    /**
     * @param  Authenticatable  $user  The resolved auth user; must use Sanctum's HasApiTokens
     *                                 (the config-bound `auth.providers.users.model` does), since
     *                                 `createToken()` lives on that trait, not on the interface.
     */
    public static function mint(Authenticatable $user, string $name, TokenProvenance $provenance, bool $remember): NewAccessToken
    {
        // Remember-me maps to token lifetime (ticket 06): a long expiry when the user opts to
        // stay signed in, a short one otherwise so a shared computer isn't left authenticated.
        $expiresAt = $remember
            ? now()->addDays((int) config('auth.remember.long_days', 30))
            : now()->addHours((int) config('auth.remember.short_hours', 24));

        $token = $user->createToken(substr($name, 0, 255), ['*'], $expiresAt);
        $token->accessToken->update(['provenance' => $provenance]);

        return $token;
    }
}
