<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Concerns\HasVisibility;

/**
 * A minimal HasVisibility "realm root" grantable — stands in for beam-ux's `BeamUxEntry::rootFor()`
 * shape (theme-entries-and-authoring ticket 03) so DefaultEntitlementResolver's grant cascade
 * (ACC-01) can be exercised at the package level without a beam-ux dependency.
 */
class RealmRoot extends Model
{
    use HasVisibility;

    protected $table = 'realm_roots';

    protected $guarded = [];
}
