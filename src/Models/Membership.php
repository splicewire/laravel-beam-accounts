<?php

namespace Schemastud\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function Schemastud\Beam\Accounts\accountUserModel;

class Membership extends Model
{
    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(accountUserModel(), 'user_id');
    }
}
