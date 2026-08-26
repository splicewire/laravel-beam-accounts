<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\Data;

/**
 * The passwordless passkey sign-in body — the assertion half of the WebAuthn get ceremony
 * (api-surface-coherence ticket 64).
 *
 * `remember` is declared here because the controller **reads it** (`$request->boolean('remember')`,
 * deciding the minted token's lifetime) while the inline validator never named it — ticket 28's
 * "undocumented siblings ride along" finding, on the login body. Declaring it changes nothing about
 * what is accepted (`boolean` accepts the absent case) and stops the wire carrying an
 * undocumented, behaviour-changing field.
 */
class PasskeyLoginInputData extends Data
{
    /**
     * @param  array<string, mixed>  $credential
     */
    public function __construct(
        #[Description('The single-use handle returned by the passkey login-options endpoint, naming the challenge this assertion answers.')]
        public string $handle,
        #[Description('The raw WebAuthn assertion the authenticator produced, passed through verbatim. Its shape is fixed by the WebAuthn spec, not by this API.')]
        public array $credential,
        #[Description('Whether to mint a long-lived token. Absent is the same as false.')]
        public bool|Optional|null $remember = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'handle' => 'required|string',
            'credential' => 'required|array',
            'remember' => 'sometimes|boolean',
        ];
    }
}
