<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;

/**
 * A model policed by the cascade — the shape every beam resource that denied out of the box has
 * ({@see \Splicewire\Beam\Accounts\Authorization\RolePermissions}). Nothing writes rows for it;
 * it exists so a test can assert that declaring the policy is what puts a model's tokens into the
 * derived set, without depending on which packages a host happens to compose.
 */
#[UseCascadePolicy]
class PolicedWidget extends Model
{
    protected $table = 'policed_widgets';

    protected $guarded = [];
}
