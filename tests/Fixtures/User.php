<?php

namespace Schemastud\Beam\Accounts\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Schemastud\Beam\Accounts\Concerns\BelongsToTeams;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use BelongsToTeams;
    use HasRoles;
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
        ];
    }
}
