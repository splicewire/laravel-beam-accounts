<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Data\Data;

/**
 * The account-deletion confirmation body — the acting user's current password, re-entered.
 *
 * Converted off the web `Http\Requests\ProfileDeleteRequest`. Its whole contract is one
 * `current_password` check, but it is still a declared boundary shape: the destructive verb on the
 * settings surface is exactly the one a client most needs a typed contract for, and leaving it as a
 * FormRequest kept it out of the generation chain entirely.
 */
class ProfileDeleteInputData extends Data
{
    use PasswordValidationRules;

    public function __construct(
        public string $password,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'password' => self::currentPasswordRules(),
        ];
    }
}
