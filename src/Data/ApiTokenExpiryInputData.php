<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\BeamData;

/**
 * The body of BOTH token-lifetime verbs — `renew` (extend the expiry, same secret) and `rotate`
 * (fresh secret, archive the old one).
 *
 * One class for the two on purpose: they differ only in whether the secret changes, and both take
 * exactly the same single field with the same meaning. Splitting them would publish two schemas that
 * can never legally diverge.
 *
 * `$expiresInDays` is a camelCase property carrying `#[MapInputName('expires_in_days')]`; the
 * attribute pins the accepted wire key to the snake spelling regardless of a host's global
 * CamelCaseMapper, and that wire key is what `@splicewire/beam-accounts`' `TokensClient` sends.
 *
 * `int|Optional|null` with a `= null` default rather than a plain `?int`: the field is genuinely
 * optional — its rule is `nullable` without `present`, and omitting it is the documented way to ask
 * for a token that never expires — so it must not land in the emitted schema's `required` list, and a
 * plain nullable still would.
 */
class ApiTokenExpiryInputData extends BeamData
{
    public function __construct(
        #[MapInputName('expires_in_days')]
        #[Description('The new lifetime in days, counted from now. Omit or send null for a token that never expires.')]
        public int|Optional|null $expiresInDays = null,
    ) {}

    /**
     * Rule keys are the INPUT-MAPPED (wire) names.
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
