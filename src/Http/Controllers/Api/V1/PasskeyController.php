<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Splicewire\Beam\Accounts\Passkeys\PasskeyAuthenticator;
use Splicewire\Beam\Accounts\Passkeys\PasskeyChallengeStore;
use Splicewire\Beam\Accounts\Passkeys\ResolvesPasskeyCeremonies;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Passkey management for the signed-in user (login-branding-passkey ticket 08), relocated down from Tower
 * (HTTP-07). Mints no token, so nothing to relocate on that axis; the register/list/rename/delete verbs
 * carry raw WebAuthn payloads (attestation, credential arrays) that are not clean Data bodies, so — per the
 * canonical shape's read-verb / assertion exception (asset 11 §3.4) — they stay on `Request`.
 *
 * Credentials are central-DB rows resolved through the global `{passkey}` route binding (laravel/passkeys),
 * which returns the configured model typed as the base {@see Passkey}. Type-hinting the base here (not the
 * host's Passkey subclass) keeps beam-accounts from depending UP on the host while still receiving the host
 * model instance at runtime. Registration validates the attestation through the
 * guard-agnostic authenticator; no session is opened.
 */
class PasskeyController extends Controller
{
    use ResolvesPasskeyCeremonies;

    /** Attestation challenge for the current user against the central RP. */
    public function registrationOptions(Request $request, PasskeyChallengeStore $store, PasskeyAuthenticator $authenticator): ResponseBody
    {
        $options = $authenticator->registrationOptions($request->user());
        $handle = $store->put($options, PublicKeyCredentialCreationOptions::class);

        return ResponseBody::from([
            'data' => [
                'handle' => $handle,
                'options' => WebAuthn::toBrowserArray($options),
            ],
        ]);
    }

    /** Validate the attestation and persist a named credential for the current user. */
    public function store(Request $request, PasskeyChallengeStore $store, PasskeyAuthenticator $authenticator): ResponseBody
    {
        $validated = $request->validate([
            'handle' => 'required|string',
            'name' => 'required|string|max:255',
            'credential' => 'required|array',
        ]);

        $options = $this->pullOptions($store, $validated['handle'], PublicKeyCredentialCreationOptions::class);

        if (! $options) {
            return ResponseBody::from(['message' => 'This passkey challenge has expired. Please try again.'])->invalid();
        }

        $passkey = $authenticator->register(
            $request->user(),
            $validated['name'],
            $this->credentialFromRequest($validated['credential']),
            $options,
        );

        return ResponseBody::from(['data' => $this->present($passkey)])->created();
    }

    /** The current user's credentials, name + when last used. */
    public function index(Request $request): ResponseBody
    {
        $passkeys = $request->user()->passkeys()->latest()->get()
            ->map(fn ($passkey) => $this->present($passkey))
            ->all();

        return ResponseBody::from(['data' => $passkeys]);
    }

    /** Rename a credential the current user owns (admin-redesign ticket 03). */
    public function update(Request $request, Passkey $passkey): ResponseBody
    {
        // laravel/passkeys registers a global `{passkey}` binding that resolves by id but is NOT
        // user-scoped, so enforce ownership here — a caller may only rename their own credential
        // (a missing id 404s in the binding; an other-owned id 404s here, never leaking it).
        abort_unless((string) $passkey->user_id === (string) $request->user()->getAuthIdentifier(), 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $passkey->update(['name' => $validated['name']]);

        return ResponseBody::from(['data' => $this->present($passkey->fresh())]);
    }

    /** Revoke a credential the current user owns. */
    public function destroy(Request $request, Passkey $passkey): ResponseBody
    {
        // The global `{passkey}` binding resolves to a model (unscoped by user), so — as with
        // update() — gate ownership explicitly. Prior `int $passkey` typing 500'd here because
        // the bound model was passed to an int parameter (latent: no test hit an authed delete).
        abort_unless((string) $passkey->user_id === (string) $request->user()->getAuthIdentifier(), 404);

        $passkey->delete();

        return ResponseBody::from(['message' => 'Passkey removed.'])->deleted();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            'created_at' => $passkey->created_at?->toIso8601String(),
        ];
    }
}
