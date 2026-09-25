<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose policy reserves its tokens ({@see ReservedWidgetPolicy}), standing in for a platform
 * model such as a commerce `Plan` or a tower `Conduit`: policed, and never in the derived role set.
 */
class ReservedWidget extends Model
{
    protected $table = 'reserved_widgets';

    protected $guarded = [];
}
