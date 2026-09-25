<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Rushing\PermissionCascade\Contracts\GrantedExplicitly;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;

/** A cascade policy whose tokens only an explicit `beam.accounts.roles.grants` entry hands out. */
class ReservedWidgetPolicy extends BaseModelPolicy implements GrantedExplicitly
{
    public static $defaultModelClass = ReservedWidget::class;
}
