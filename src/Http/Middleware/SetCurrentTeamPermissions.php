<?php

namespace Schemastud\Beam\Accounts\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

use function Schemastud\Beam\Accounts\accountGuard;

use Spatie\Permission\PermissionRegistrar;

/**
 * Bind spatie's team scope to the authenticated user's current team for the
 * request, so the permission cascade resolves against the right team. Add this
 * to the app's `web` group for the whole authed surface to be team-scoped.
 */
class SetCurrentTeamPermissions
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user(accountGuard());

        if ($user && method_exists($user, 'currentTeamOrPersonal')) {
            $team = $user->currentTeamOrPersonal();

            if ($team) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
            }
        }

        return $next($request);
    }
}
