<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Data\BeamData;

/** Package-owned token lifecycle response contract. */
#[TypeScript]
class ApiTokenData extends BeamData
{
    public function __construct(
        /**
         * The token's KEY as a string, whatever shape the host's key is.
         *
         * ⚠️ Was `int`, and `ApiTokenController::present()` cast to match. Measured 2026-09-12 at
         * `https://fresh-tower.test`: on a uuid-keyed host every roster row claimed the same id, and
         * Archive issued `DELETE /beam/accounts/tokens/1` and got 500. A uuid key is a documented
         * HOST shape (`beam.accounts.tokens.model`), tower, satellite and the flagship all run one,
         * and the package's bigint default is why every existing assertion agreed with itself.
         *
         * The sibling particle projection `TokenData::$id` had always been a string, and
         * `is_current` on this very class compares `(string) $token->getKey()`. This brings the REST
         * DTO to the settled rule `ImpersonationTest` states for a shared shape: string keys, so a
         * uuid-keyed and a bigint-keyed host share one shape.
         */
        public string $id,
        public string $name,
        /** Where the token came from — drives the type chip + facet filter. */
        public TokenProvenance $provenance,
        /**
         * The token's granted scope (ADR-0109): the permission-names it may exercise, or `null`
         * for an unscoped ("Full access") token. A ceiling, not a guarantee.
         *
         * @var string[]|null
         */
        public ?array $abilities,
        public ?string $created_at,
        public ?string $last_used_at,
        /** When the token stops working, or null for a token that never expires. */
        public ?string $expires_at,
        /** When the token was archived (soft-revoked, retained for audit), or null if live. */
        public ?string $archived_at,
        /** True for the session token authenticating the current request — never swept/lockout. */
        public bool $is_current,
    ) {}
}
