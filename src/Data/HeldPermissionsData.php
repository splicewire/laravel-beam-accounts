<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The permission names the acting principal currently holds — the CEILING a scoped token may draw
 * from (ADR-0109: enforcement applies `token abilities ∩ the user's live permissions`).
 *
 * It exists because the scoped-create picker needs that list and the flagship reads it from `GET me`
 * — a resource a bare beam host does not necessarily mount. Read from the token surface instead, the
 * coupling is to the surface that needs it rather than to another resource's exposure, and
 * `@splicewire/beam-accounts`' `TokensClient.listPermissions()` has one endpoint at every host.
 *
 * A Data class rather than a bare `{data: [...]}` array, so the response is a declared shape like
 * every other verb on this surface. The extra `permissions` key is the cost of that and is the same
 * shape `me` already answers with.
 */
#[TypeScript]
class HeldPermissionsData extends BeamData
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        /** @var string[] */
        public array $permissions,
    ) {}
}
