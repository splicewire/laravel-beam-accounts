<?php

namespace Splicewire\Beam\Accounts\Entitlements\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Selects and authorizes the records whose access grants are read on behalf of a principal.
 *
 * A source must return only records the principal may act through; member-equivalent seats must be
 * excluded. The resolver trusts every returned record and does not repeat eligibility checks. This
 * is the grantee-selection seam configured at `beam.accounts.entitlements.grantees`; the separate
 * RealmGrantable seam maps those records' grantable ids to realm names.
 */
interface GranteeSource
{
    /** @return iterable<Model> */
    public function granteesFor(mixed $principal): iterable;
}
