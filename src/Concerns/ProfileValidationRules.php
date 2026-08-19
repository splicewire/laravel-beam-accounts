<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;

trait ProfileValidationRules
{
    /**
     * @param  int|string|null  $userId  The current user's key for unique-ignore-self. Widened from
     *                                   `?int` (HTTP-07): the account user model is UUID-keyed
     *                                   (`HasUuids`), so `getKey()` is a string — the former `?int`
     *                                   hint could never receive the real key without a TypeError.
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public static function profileRules(int|string|null $userId = null): array
    {
        return [
            'name' => self::nameRules(),
            'email' => self::emailRules($userId),
        ];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    public static function emailRules(int|string|null $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(BeamAccounts::userModel())
                : Rule::unique(BeamAccounts::userModel())->ignore($userId),
        ];
    }
}
