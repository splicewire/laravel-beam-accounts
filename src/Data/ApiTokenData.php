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
        public int $id,
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
