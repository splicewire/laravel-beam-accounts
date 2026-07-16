<?php

namespace Schemastud\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use function Schemastud\Beam\Accounts\accountUserModel;

class Team extends Model
{
    protected $guarded = [];

    protected $casts = [
        'personal_team' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(accountUserModel(), 'user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function members()
    {
        return $this->belongsToMany(accountUserModel(), 'memberships')
            ->withPivot('role')
            ->withTimestamps();
    }
}
