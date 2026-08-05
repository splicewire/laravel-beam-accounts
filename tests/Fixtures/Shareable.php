<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Concerns\HasUserId;
use Rushing\PermissionCascade\Concerns\HasVisibility;

/**
 * A minimal HasVisibility resource to exercise the generic sharing services (AccessGrants /
 * ViewRequests) at the package level.
 */
class Shareable extends Model
{
    use HasUserId;
    use HasVisibility;

    protected $table = 'shareables';

    protected $guarded = [];
}
