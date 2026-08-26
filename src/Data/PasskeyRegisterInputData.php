<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\Data;

/**
 * The passkey-registration body — the attestation half of the WebAuthn create ceremony
 * (api-surface-coherence ticket 64, inheriting ticket 27's convention).
 *
 * The controller's docblock argues that the raw WebAuthn payload "is not a clean Data body" and so
 * `register`/`verify` stay on `Request`. That argument is about the **runtime binding**, not about the
 * declaration: `credential` is an opaque authenticator-produced object this package deliberately does
 * not model, but `handle` and `name` are ours, and the endpoint's body still has to reach the OpenAPI
 * document and the generated client as something. Declaring it here publishes the two fields a caller
 * actually authors and one free-form object, instead of Scribe's rule-derived faker guess.
 *
 * The runtime binding is unchanged — the controller still reads `$request->validate()`-shaped input and
 * is never handed this class by the container, which is 27's gate-order rule (an injected DTO validates
 * during resolution and puts 422 ahead of `authorize`).
 */
class PasskeyRegisterInputData extends Data
{
    /**
     * @param  array<string, mixed>  $credential
     */
    public function __construct(
        #[Description('The single-use handle returned by the registration-options endpoint, naming the challenge this attestation answers. Expires; a stale handle is refused with 422.')]
        public string $handle,
        #[Description('The label the user chooses for this credential, shown in their passkey list.')]
        public string $name,
        #[Description('The raw WebAuthn attestation the authenticator produced, passed through verbatim. Its shape is fixed by the WebAuthn spec, not by this API.')]
        public array $credential,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'handle' => 'required|string',
            'name' => 'required|string|max:255',
            'credential' => 'required|array',
        ];
    }
}
