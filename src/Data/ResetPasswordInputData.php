<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/**
 * The reset-confirmation body — token + email + new password (auth-cluster spec asset 11 §3.3).
 *
 * Converted off Tower's `ResetPasswordRequest` FormRequest (HTTP-07). It **already** sourced its
 * password policy from beam-accounts' {@see PasswordValidationRules} ("one source of truth"), and that
 * carries over verbatim: `passwordRules('passwordConfirmation')` becomes the InputData's own rule for the
 * `password` field, so the SPA reset can't drift from the engine's Fortify reset. The confirmation is a promoted
 * property because it is part of the strict public wire even though the controller only passes it to Laravel's broker.
 */
class ResetPasswordInputData extends BeamData
{
    use PasswordValidationRules;
    use RejectsUnknownInputKeys;

    public function __construct(
        public string $token,
        public string $email,
        public string $password,
        #[MapName('passwordConfirmation')]
        #[Description('The new password again; it must match `password` exactly.')]
        public string $passwordConfirmation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'token' => 'required|string',
            'email' => 'required|string|email',
            'password' => self::passwordRules('passwordConfirmation'),
            'passwordConfirmation' => ['required', 'string'],
        ];
    }
}
