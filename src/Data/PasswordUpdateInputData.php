<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Data\BeamData;

/**
 * The self-service password-change body — current password plus the new one.
 *
 * Converted off the web `Http\Requests\PasswordUpdateRequest`, which was the last write on the
 * settings surface with no declared shape at all: the particle doctrine's invariant covers every
 * boundary-crossing shape, and an Inertia route is not one of its four exceptions. The rules stay
 * sourced from {@see PasswordValidationRules} so the web change-password form and any future API
 * twin cannot drift apart.
 *
 * `password` carries Laravel's `confirmed` rule (via `passwordRules()`), so the wire also accepts
 * `password_confirmation`; it is deliberately NOT a promoted property, since it is a validation-only
 * companion the controller never reads.
 *
 * `#[MapInputName(SnakeCaseMapper::class)]` because the wire name `current_password` is a fixed
 * existing contract — Laravel's `current_password` validation rule and the shipped change-password
 * form both use it — while the PHP side stays camelCase like every other DTO here. The mapper is the
 * seam between the two; renaming either side would have been a breaking change for no gain.
 */
#[MapInputName(SnakeCaseMapper::class)]
class PasswordUpdateInputData extends BeamData
{
    use PasswordValidationRules;

    public function __construct(
        public string $currentPassword,
        public string $password,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'current_password' => self::currentPasswordRules(),
            'password' => self::passwordRules(),
        ];
    }
}
