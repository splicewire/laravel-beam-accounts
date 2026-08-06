<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\ProfileValidationRules;
use Splicewire\Beam\Data\Data;

/**
 * The self-service profile-edit body — name + email (auth-cluster spec asset 11 §3.5).
 *
 * Converted off Tower's API `ProfileUpdateRequest` FormRequest (HTTP-07). The reconciliation (asset 08
 * Part 2) folded the request's **inline** name/email dup into beam-accounts'
 * {@see ProfileValidationRules}: `profileRules($userId)` is the one rule source, and its email rule is
 * unique-ignore-self. The ignore-self id is context-dependent (the authenticated user).
 */
class ProfileUpdateInputData extends Data
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    /**
     * Spatie resolves `rules()` via `app()->call([static::class, 'rules'])` — a STATIC call (matching
     * every other InputData in this estate; a non-static hook would force the container to instantiate a
     * DTO with required ctor args and 500). The `ProfileValidationRules` methods are protected instance
     * methods (shared verbatim with the web FormRequest via `$this->`), so we reach `profileRules()` here
     * through a transient carrier — the name/email policy stays sourced from the one beam-accounts concern.
     *
     * The email rule is unique-ignore-self keyed on the authenticated user's id (the principal in a tenant
     * context is a TenantUser whose id mirrors the central User of record; `accountUserModel()` maps the
     * uniqueness back to the central users table). The route's `auth:sanctum` tier guarantees a user on a
     * real request — the null-safe access is only exercised by Scribe's rules() introspection (no auth
     * context), where `ignore(null)` is a harmless no-op.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        $rules = new class
        {
            use ProfileValidationRules;

            public function forUser(int|string|null $userId): array
            {
                return $this->profileRules($userId);
            }
        };

        return $rules->forUser(auth()->user()?->getKey());
    }
}
