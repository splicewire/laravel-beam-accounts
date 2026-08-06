<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\PasswordValidationRules;
use Splicewire\Beam\Data\Data;

/**
 * The reset-confirmation body — token + email + new password (auth-cluster spec asset 11 §3.3).
 *
 * Converted off Tower's `ResetPasswordRequest` FormRequest (HTTP-07). It **already** sourced its
 * password policy from beam-accounts' {@see PasswordValidationRules} ("one source of truth"), and that
 * carries over verbatim: `passwordRules()` becomes the InputData's own rule for the `password` field, so
 * the SPA reset can't drift from the engine's Fortify reset.
 */
class ResetPasswordInputData extends Data
{
    public function __construct(
        public string $token,
        public string $email,
        public string $password,
    ) {}

    /**
     * Spatie resolves `rules()` via `app()->call([static::class, 'rules'])` — a STATIC call (matching
     * every other InputData in this estate; a non-static hook would force the container to instantiate a
     * DTO with required ctor args and 500). The `PasswordValidationRules` methods are protected instance
     * methods (shared verbatim with the Fortify/web FormRequests via `$this->`), so we reach them here
     * through a transient carrier that exposes `passwordRules()` — the password policy stays sourced from
     * the one beam-accounts concern, never re-declared.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        $rules = new class
        {
            use PasswordValidationRules;

            public function password(): array
            {
                return $this->passwordRules();
            }
        };

        return [
            'token' => 'required|string',
            'email' => 'required|string|email',
            'password' => $rules->password(),
        ];
    }
}
