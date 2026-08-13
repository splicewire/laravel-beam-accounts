<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A WebAuthn ceremony challenge as the passkey option endpoints emit it in the ResponseBody `data`
 * slot: the cache `handle` the client echoes back to resolve the pending ceremony, plus the
 * browser-ready `options` map fed straight to `navigator.credentials.create()`/`.get()`.
 *
 * Serves BOTH ceremonies — `passkeys.registration-options` (a PublicKeyCredentialCreationOptions
 * attestation challenge) and `passkey.login-options` (a PublicKeyCredentialRequestOptions assertion
 * challenge). `options` is deliberately an untyped map: the shape is webauthn-lib's serializer output
 * (`WebAuthn::toBrowserArray()`), a spec-defined structure this package does not own and will not
 * re-declare field-by-field — an honest `Record`, not a lie of precision.
 *
 * `#[TypeScript]`-emitted so the host can declare it via `->returns()` and codegen derives the
 * option endpoints' hook types (sdk.returns-coverage).
 */
#[TypeScript]
class PasskeyOptionsData extends Data
{
    public function __construct(
        public string $handle,
        /**
         * The browser-consumable WebAuthn options map (creation or request, per endpoint).
         *
         * @var array<string, mixed>
         */
        public array $options,
    ) {}
}
