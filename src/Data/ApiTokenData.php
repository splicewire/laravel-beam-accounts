<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Attributes\MapName;
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
         * `isCurrent` on this very class compares `(string) $token->getKey()`. This brings the REST
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
        #[MapName('createdAt')]
        public ?string $createdAt,
        #[MapName('lastUsedAt')]
        public ?string $lastUsedAt,
        /** When the token stops working, or null for a token that never expires. */
        #[MapName('expiresAt')]
        public ?string $expiresAt,
        /** When the token was archived (soft-revoked, retained for audit), or null if live. */
        #[MapName('archivedAt')]
        public ?string $archivedAt,
        /** True for the session token authenticating the current request — never swept/lockout. */
        #[MapName('isCurrent')]
        public bool $isCurrent,
        /**
         * The last four characters of the token's plaintext, captured at mint, so tokens that share
         * a name can be told apart (owner-review-20261102 K-28).
         *
         * Null for every token minted before the `last_four` column existed, at a host that has not
         * migrated it yet, and for the mint paths that do not stamp it (sign-in sessions, federation
         * grants, deterministic service keys). The plaintext of those rows is gone, so the client
         * shows the tail of {@see self::$id} instead and labels it as the id.
         *
         * Last, and defaulted to null, on purpose: `splicewire/tower` builds this DTO in its own
         * controller, and its live checkout follows this package's through a path link. A required
         * argument would 500 tower's token list from the moment this package moved until tower did.
         * A host that has not been updated lists `null`, which the roster already renders as the id
         * tail.
         */
        #[MapName('lastFour')]
        public ?string $lastFour = null,
    ) {}
}
