<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Laravel\Passkeys\Support\WebAuthn;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Passkeys\PasskeyAuthenticator;
use Splicewire\Beam\Accounts\Passkeys\PasskeyChallengeStore;
use Splicewire\Beam\Accounts\Passkeys\ResolvesPasskeyCeremonies;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Passwordless passkey sign-in (login-branding-passkey ticket 09), relocated down from Tower (HTTP-07).
 *
 * Already modelled the target shape: it mints in-controller and passes the plaintext into the response
 * (asset 11 §3.2), so there is NO mint to relocate here — only the projection swaps from `AuthUserResource`
 * to the pure {@see AuthUserData::fromUser}. The credential is a WebAuthn assertion, not a clean Data body,
 * so `verify` stays on `Request` for the raw assertion. The passkey primitives it consumes now sit in the
 * same package. Deliberately excluded from the generated API reference.
 */
class PasskeyLoginController extends Controller
{
    use ResolvesPasskeyCeremonies;

    /**
     * Assertion challenge for the central RP. Discoverable (no user) → passwordless.
     */
    public function options(PasskeyChallengeStore $store, PasskeyAuthenticator $authenticator): ResponseBody
    {
        $options = $authenticator->loginOptions();
        $handle = $store->put($options, PublicKeyCredentialRequestOptions::class);

        return ResponseBody::from([
            'data' => [
                'handle' => $handle,
                'options' => WebAuthn::toBrowserArray($options),
            ],
        ]);
    }

    /**
     * Validate the assertion, resolve the user, and mint a passkey-stamped Sanctum token.
     */
    #[ResponseFromData(AuthUserData::class)]
    public function verify(Request $request, PasskeyChallengeStore $store, PasskeyAuthenticator $authenticator): ResponseBody
    {
        $validated = $request->validate([
            'handle' => 'required|string',
            'credential' => 'required|array',
        ]);

        $options = $this->pullOptions($store, $validated['handle'], PublicKeyCredentialRequestOptions::class);

        if (! $options) {
            return ResponseBody::from(['message' => 'This passkey challenge has expired. Please try again.'])
                ->unauthorized();
        }

        $user = $authenticator->resolveUserFromAssertion($this->credentialFromRequest($validated['credential']), $options);

        if (! $user) {
            return ResponseBody::from(['message' => 'Passkey verification failed.'])->unauthorized();
        }

        // Mint a passkey-stamped token via the shared factory (same expiry/stamping as the session mint),
        // then project the pure identity core off it — the mint lives here, never inside the Data.
        $token = AuthTokenFactory::mint($user, $request->userAgent() ?? 'passkey', TokenProvenance::Passkey, $request->boolean('remember'));

        return ResponseBody::from(['data' => AuthUserData::fromUser($user, $token->plainTextToken)]);
    }
}
