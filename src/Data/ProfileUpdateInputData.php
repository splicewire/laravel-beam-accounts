<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Concerns\ProfileValidationRules;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Data\BeamData;

/**
 * The self-service profile-edit body — name + email (auth-cluster spec asset 11 §3.5).
 *
 * Converted off Tower's API `ProfileUpdateRequest` FormRequest (HTTP-07). The reconciliation (asset 08
 * Part 2) folded the request's **inline** name/email dup into beam-accounts'
 * {@see ProfileValidationRules}: `profileRules($userId)` is the one rule source, and its email rule is
 * unique-ignore-self. The ignore-self id is context-dependent (the authenticated user).
 */
class ProfileUpdateInputData extends BeamData
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    use ProfileValidationRules;

    /**
     * Spatie resolves `rules()` via `app()->call([static::class, 'rules'])` — a STATIC call (matching
     * every other InputData in this estate; a non-static hook would force the container to instantiate a
     * DTO with required ctor args and 500). `ProfileValidationRules::profileRules()` is now itself
     * public static, so this is a direct call — it used to route through a transient anonymous-class
     * carrier only because the trait's methods were protected instance methods, shared by `$this->` with
     * the web `ProfileUpdateRequest`. That FormRequest is gone, so the carrier went with it.
     *
     * The email rule is unique-ignore-self keyed on the authenticated user's id (the principal in a tenant
     * context is a TenantUser whose id mirrors the central User of record; `BeamAccounts::userModel()` maps the
     * uniqueness back to the central users table). The route's auth tier guarantees a user on a
     * real request — the null-safe access is only exercised by Scribe's rules() introspection (no auth
     * context), where `ignore(null)` is a harmless no-op.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return self::profileRules(auth()->user()?->getKey());
    }
}
