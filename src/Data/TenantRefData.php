<?php

namespace Splicewire\Beam\Accounts\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One entry in {@see AuthUserData::$tenants} — the tenant list the SPA's switcher renders.
 *
 * ## Why this class exists
 *
 * It was a hand-built `array<string, mixed>` returned by `AuthUserData::tenantRow()`, and `$tenants`
 * was typed `array<int, array<string, mixed>>`. That is an **undeclared shape on a boundary**, which
 * the particle doctrine's one invariant forbids: every boundary-crossing data shape is a declared
 * Data class. The array crossed the wire on every authenticated request and no instrument could see
 * it — not the wire-name audit (no properties to inspect), not codegen (nothing to generate from),
 * not the schema projection.
 *
 * The cost was already being paid downstream: `ui/src/stores/user.ts` hand-writes
 * `primaryHost?: string`, because there was no generated type to import.
 *
 * ⚠️ **`primaryHost` is camelCase and must stay that way.** It is the key that ships today, and
 * `ui/src/app/shell/SystemZone.tsx:27` reads it directly (`tenant.primaryHost?.split('.')[0]`) to
 * label the tenant switcher. Declaring the existing key is the entire point of this class; renaming
 * it to `primary_host` would be a breaking change wearing a cleanup's clothes — the same trap
 * `AuthUserData::$isRoot` presented, and for the same reason (`output` maps no names at the
 * flagship, so an undeclared property publishes its own PHP name).
 *
 * ## Not a particle
 *
 * No `#[ParticleResource]`: this is a projection nested inside another DTO, not an addressable
 * resource. A tenant IS addressable elsewhere — this is the thin reference the auth payload carries,
 * which is why it is `TenantRef` rather than `Tenant`.
 */
#[TypeScript]
#[Description('A tenant the authenticated user can reach — the thin reference the auth payload carries, not the full record.')]
class TenantRefData extends BeamData
{
    public function __construct(
        public string $id,
        public string $name,
        #[Description('The tenant\'s first configured domain, or null when it has none.')]
        public ?string $domain = null,
        #[Description('The tenant\'s primary host. camelCase on the wire — see the class docblock before "fixing" it.')]
        public ?string $primaryHost = null,
    ) {}
}
