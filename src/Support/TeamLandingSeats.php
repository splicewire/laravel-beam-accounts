<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Contracts\LandingSeats;
use Splicewire\Beam\Accounts\Landing;

/**
 * The package default: a seat is a team membership, and every seat lands on the tenant home (one host, so the team the
 * user works in is chosen inside the app, not by the landing URL).
 */
class TeamLandingSeats implements LandingSeats
{
    public function homeFor(Authenticatable $user): ?string
    {
        if (! method_exists($user, 'memberships') || ! $user->memberships()->exists()) {
            return null;
        }

        return Landing::home('tenant');
    }
}
