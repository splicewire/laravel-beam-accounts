<?php

namespace Splicewire\Beam\Accounts\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;

/**
 * Bind spatie's team scope to the authenticated user's current team for the
 * request, so the permission cascade resolves against the right team. Add this
 * to the app's `web` group for the whole authed surface to be team-scoped.
 */
class SetCurrentTeamPermissions
{
    public function handle(Request $request, Closure $next)
    {
        // The account guard first (a session sign-in), then whichever guard authenticated this request: `auth:sanctum`
        // makes Sanctum the default, and a bearer request has no session user, so reading the account guard alone left
        // the team unset and every team-scoped role invisible on a token-authenticated route (L3 ticket 09 C4: a seated
        // owner refused the UX builder). With neither, no team is bound: the cascade fails closed.
        $user = $request->user(BeamAccounts::guard()) ?? $request->user();

        if ($user && method_exists($user, 'currentTeamOrPersonal')) {
            $team = $user->currentTeamOrPersonal();

            if ($team) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
            }
        }

        return $next($request);
    }
}
