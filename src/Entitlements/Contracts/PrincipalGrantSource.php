<?php

namespace Splicewire\Beam\Accounts\Entitlements\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Selects the records whose access grants are read on behalf of an entitlement principal.
 *
 * This is the principal-grant source seam configured at
 * `beam.accounts.entitlements.principal_grant_source`. It chooses grant-holding records; the
 * separate RealmGrantable seam maps those records' grantable ids to realm names.
 */
interface PrincipalGrantSource
{
    /** @return iterable<Model> */
    public function granteesFor(mixed $principal): iterable;
}
