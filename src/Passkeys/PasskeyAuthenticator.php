<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Passkeys;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Guard-agnostic WebAuthn passkey seam.
 *
 * This is the load-bearing decision (login-branding-passkey ticket 07): it validates a
 * browser assertion and **returns the resolved user without logging anyone in**. That single
 * property is what lets a session-based host (Fortify) and a token-based host (this app's
 * Sanctum mint) both consume the same validation — the host decides what to do with the
 * resolved user (open a session, mint a token, …), the package never touches a guard.
 *
 * It is a thin wrapper over `laravel/passkeys` (which wraps `web-auth/webauthn-lib`): the RP
 * identity, credential model, option builders, and assertion/attestation validators all live
 * there and are configured by the host (RP ID via `config('passkeys.relying_party_id')`),
 * never hard-coded here. This class deliberately does NOT import the package's session login
 * controller — it exposes only the validator + option builders the host's own controllers call.
 */
class PasskeyAuthenticator
{
    public function __construct(
        private GenerateVerificationOptions $verificationOptions,
        private GenerateRegistrationOptions $registrationOptions,
        private VerifyPasskey $verifyPasskey,
        private StorePasskey $storePasskey,
    ) {}

    /**
     * Build assertion (login) options — a challenge for the host RP. With no user this is a
     * passwordless/discoverable challenge; with a user it narrows to that user's credentials.
     */
    public function loginOptions(?PasskeyUser $user = null): PublicKeyCredentialRequestOptions
    {
        return ($this->verificationOptions)($user);
    }

    /**
     * Build attestation (registration) options — a challenge for the host RP, excluding the
     * user's already-registered credentials. The user must implement PasskeyUser.
     */
    public function registrationOptions(Authenticatable $user): PublicKeyCredentialCreationOptions
    {
        return ($this->registrationOptions)($user);
    }

    /**
     * Validate a browser assertion and RESOLVE THE USER — no login, no session, no token.
     *
     * Returns the user behind the matched credential, or null when validation fails. When
     * `$expected` is supplied the assertion must belong to that user. This method never calls
     * `Auth::login()` / touches a guard — that is the whole point of the seam.
     */
    public function resolveUserFromAssertion(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
        ?PasskeyUser $expected = null,
    ): ?Authenticatable {
        try {
            $passkey = ($this->verifyPasskey)($credential, $options, $expected);
        } catch (InvalidPasskeyException) {
            return null;
        }

        return $passkey->user;
    }

    /**
     * Validate an attestation and persist a new named credential for the user. Throws
     * InvalidPasskeyException on a bad/duplicate attestation (the host maps it to a 4xx).
     */
    public function register(
        Authenticatable $user,
        string $name,
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options,
    ): Passkey {
        return ($this->storePasskey)($user, $name, $credential, $options);
    }
}
