<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

use function Splicewire\Beam\Accounts\accountUserModel;

trait ProfileValidationRules
{
    /**
     * @param  int|string|null  $userId  The current user's key for unique-ignore-self. Widened from
     *                                   `?int` (HTTP-07): the account user model is UUID-keyed
     *                                   (`HasUuids`), so `getKey()` is a string — the former `?int`
     *                                   hint could never receive the real key without a TypeError.
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(int|string|null $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(int|string|null $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(accountUserModel())
                : Rule::unique(accountUserModel())->ignore($userId),
        ];
    }
}
