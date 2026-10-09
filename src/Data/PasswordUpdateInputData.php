<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/**
 * The self-service password-change body — current password plus the new one.
 *
 * Converted off the web `Http\Requests\PasswordUpdateRequest`, which was the last write on the
 * settings surface with no declared shape at all: the particle doctrine's invariant covers every
 * boundary-crossing shape, and an Inertia route is not one of its four exceptions. The rules stay
 * sourced from {@see PasswordValidationRules} so the web change-password form and any future API
 * twin cannot drift apart.
 *
 * `passwordConfirmation` is promoted even though the controller never reads it: it is part of the
 * HTTP contract, and strict input must recognize exactly that camel wire key while refusing the old
 * snake companion. Every multi-word field carries an explicit camel `#[MapName]` so host mappers
 * cannot change the published contract.
 */
class PasswordUpdateInputData extends BeamData
{
    use PasswordValidationRules;
    use RejectsUnknownInputKeys;

    public function __construct(
        #[MapName('currentPassword')]
        public string $currentPassword,
        public string $password,
        #[MapName('passwordConfirmation')]
        public string $passwordConfirmation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'currentPassword' => self::currentPasswordRules(),
            'password' => self::passwordRules('passwordConfirmation'),
            'passwordConfirmation' => ['required', 'string'],
        ];
    }
}
