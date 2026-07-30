<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Beam;

class Invitation extends Model
{
    protected $guarded = [];

    /**
     * `invitations` → `beam_invitations`, via the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename ticket 04).
     */
    public function getTable(): string
    {
        return Beam::table('invitations');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
