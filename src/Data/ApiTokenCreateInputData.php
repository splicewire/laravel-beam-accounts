<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Accounts\Http\Controllers\Account\ApiTokenController;
use Splicewire\Beam\Data\BeamData;

/**
 * The `POST {api_root}/tokens` body — the request half of the reveal-once mint.
 *
 * This is the HOST ESCAPE HATCH {@see TokenData}'s docblock names in terms: *"CREATE stays a HOST
 * escape hatch — Frame's generic create has no notion of a create-response carrying a display-once
 * secret… The reveal-once create + rotate/renew lifecycle stay a host REST survivor."* The survivor
 * now ships FROM the package that owns the semantics instead of being re-authored per host —
 * {@see ApiTokenController} is the one implementation and this is its declared input.
 *
 * Sits beside {@see ApiTokenData} (the list read-model) and {@see CreatedTokenData} (the reveal-once
 * mint response). Both of those already shipped here and were served by nothing: the package declared
 * the response shapes of a surface it did not mount. This class and the controller close that.
 *
 * `$expiresInDays` is a camelCase property carrying `#[MapInputName('expires_in_days')]`. The
 * attribute pins the accepted WIRE key to the snake spelling regardless of a host's global
 * CamelCaseMapper; the property name is house style and is not part of the contract. The wire key is
 * what `@splicewire/beam-accounts`' `TokensClient.create()` sends, so it is load-bearing.
 *
 * `abilities` and `expires_in_days` are `X|Optional|null` unions with `= null` defaults rather than
 * plain nullables: both are genuinely optional (an omitted `abilities` means the unscoped `['*']`
 * default, an omitted `expires_in_days` means a token that never expires), and a plain nullable still
 * lands in the emitted schema's `required` list — only the `Optional` union escapes it. The `= null`
 * default means a missing key hydrates as null rather than as an `Optional` instance, so the
 * controller reads the properties directly.
 */
class ApiTokenCreateInputData extends BeamData
{
    public function __construct(
        #[Description('A human label for the token, shown in the token list. Not a secret and not unique.')]
        public string $name,
        /**
         * A subset of the permission names the minting principal holds. Omit, send an empty list, or
         * include `*` for an unscoped token that acts fully as you. Any other list is clamped at mint
         * against the permissions you actually hold, so a token can never over-state its own reach.
         *
         * @var array<int, string>|null
         */
        #[Description('The permission names to scope the token to. Omit (or include `*`) for an unscoped token that acts fully as you. You can only scope *down* from yourself — naming a permission you do not hold is a 422.')]
        public array|Optional|null $abilities = null,
        #[MapInputName('expires_in_days')]
        #[Description('Lifetime in days. Omit or send null for a token that never expires. Sanctum enforces per-token expiry regardless of the global setting.')]
        public int|Optional|null $expiresInDays = null,
    ) {}

    /**
     * Rule keys are the INPUT-MAPPED (wire) names.
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['array'],
            'abilities.*' => ['string'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
