<?php

namespace Splicewire\Beam\Accounts\Entitlements;

use Splicewire\Beam\Accounts\Entitlements\Contracts\PrincipalGrantSource;
use Splicewire\Beam\Accounts\Enums\Role;

/** The default principal-grant source: eligible Owner/Admin team memberships. */
class TeamPrincipalGrantSource implements PrincipalGrantSource
{
    /** @return iterable<\Illuminate\Database\Eloquent\Model> */
    public function granteesFor(mixed $principal): iterable
    {
        if (! is_object($principal) || ! method_exists($principal, 'teams')) {
            return [];
        }

        $teams = [];

        foreach ($principal->teams as $team) {
            $role = $team->pivot->role ?? null;
            $role = $role !== null ? Role::tryFrom($role) : null;

            // A null (absent) or unrecognized role degrades to ineligible rather than throwing —
            // a stale/hand-edited membership row must never fatal every entitlement Gate check.
            if ($role === null || ! $role->grantEligible()) {
                continue;
            }

            $teams[] = $team;
        }

        return $teams;
    }
}
