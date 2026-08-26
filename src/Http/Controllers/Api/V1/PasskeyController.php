<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Splicewire\Beam\Accounts\Data\PasskeyRegisterInputData;
use Splicewire\Beam\Accounts\Data\PasskeyRenameInputData;
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
 *
 * The "raw WebAuthn payloads stay on `Request`" exception above is about the **container binding**, and it
 * still holds — nothing here is injected as a typed Data parameter. It never meant the bodies go
 * undeclared: `store` and `update` now name {@see PasskeyRegisterInputData} / {@see PasskeyRenameInputData}
 * and hydrate them below their gates (api-surface-coherence ticket 64), so the document and the generated
 * client carry the two fields a caller authors plus one opaque, spec-shaped `credential` object, instead of
 * a rule-derived faker guess.
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

    /**
     * Register a passkey
     *
     * Complete passkey registration: validate the attestation produced by the authenticator against the
     * challenge issued by the registration-options endpoint, and store it under a name the user chooses.
     */
    #[RequestFromData(PasskeyRegisterInputData::class)]
    public function store(Request $request, PasskeyChallengeStore $store, PasskeyAuthenticator $authenticator): ResponseBody
    {
        // Hydrated below any gate, never injected as a typed parameter — injection validates during
        // container resolution and would put 422 ahead of authorization (ticket 27's measured trap).
        $input = PasskeyRegisterInputData::validateAndCreate($request);

        $options = $this->pullOptions($store, $input->handle, PublicKeyCredentialCreationOptions::class);

        if (! $options) {
            return ResponseBody::from(['message' => 'This passkey challenge has expired. Please try again.'])->invalid();
        }

        $passkey = $authenticator->register(
            $request->user(),
            $input->name,
            $this->credentialFromRequest($input->credential),
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

    /** Rename a passkey
     *
     * Change the label on one of your own registered credentials. */
    #[RequestFromData(PasskeyRenameInputData::class)]
    public function update(Request $request, Passkey $passkey): ResponseBody
    {
        // laravel/passkeys registers a global `{passkey}` binding that resolves by id but is NOT
        // user-scoped, so enforce ownership here — a caller may only rename their own credential
        // (a missing id 404s in the binding; an other-owned id 404s here, never leaking it).
        abort_unless((string) $passkey->user_id === (string) $request->user()->getAuthIdentifier(), 404);

        // Below the ownership gate, so a caller who does not own the credential still gets 404 rather
        // than a 422 that would confirm the id exists and leak the field vocabulary.
        $input = PasskeyRenameInputData::validateAndCreate($request);

        $passkey->update(['name' => $input->name]);

        return ResponseBody::from(['data' => $this->present($passkey->fresh())]);
    }

    /** Revoke a credential the current user owns. */
    public function destroy(Request $request, Passkey $passkey): ResponseBody
    {
        // The global `{passkey}` binding resolves to a model (unscoped by user), so — as with
        // update() — gate ownership explicitly. Prior `int $passkey` typing 500'd here because
        // the bound model was passed to an int parameter (latent: no test hit an authed delete).
        abort_unless((string) $passkey->user_id === (string) $request->user()->getAuthIdentifier(), 404);

        // Snapshot the row before deletion so the data slot carries the removed credential's
        // final state (the destroy-returns-the-resource envelope rule), matching PasskeyData.
        $snapshot = $this->present($passkey);

        $passkey->delete();

        return ResponseBody::from(['message' => 'Passkey removed.', 'data' => $snapshot])->deleted();
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
