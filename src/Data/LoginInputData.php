<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\BeamData;

/**
 * The first-party SPA password-grant login body (auth-cluster spec asset 11 §3.1).
 *
 * Converted off the retired Tower `LoginRequest` FormRequest (HTTP-07): its request-scoped rules
 * become this InputData's own `rules()`, run on resolution under beam's OnlyRequests strategy. No
 * shared policy applies (email + password + remember is login-specific), so the rules stay local.
 */
class LoginInputData extends BeamData
{
    public function __construct(
        public string $email,
        public string $password,
        public ?bool $remember = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => 'required|string|email',
            'password' => 'required|string',
            'remember' => 'sometimes|boolean',
        ];
    }
}
