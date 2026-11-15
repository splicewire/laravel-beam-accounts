<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The reset-link request body (auth-cluster spec asset 11 §3.3).
 *
 * Converted off Tower's `ForgotPasswordRequest` FormRequest (HTTP-07). Email-only, no shared policy;
 * the rule is local. Enumeration-safety is enforced by the controller's identical-response contract,
 * not here.
 */
#[TypeScript]
class ForgotPasswordInputData extends BeamData
{
    public function __construct(
        public string $email,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'email' => 'required|string|email',
        ];
    }
}
