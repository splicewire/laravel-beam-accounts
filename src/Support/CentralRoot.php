<?php

namespace Splicewire\Beam\Accounts\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\Permission\PermissionRegistrar;

/**
 * The central-Root check, flip-safe (admin-redesign ticket 02, Q1 — the `centralUserIsRoot()`
 * null-team footgun).
 *
 * Roles are team-scoped by `tenant_id` (spatie teams). In tenant context the current
 * permissions team is the tenant, so a bare `hasRole('Root')` sees only the *tenant* role and
 * misses Root — which is assigned on the central (null) team. Any operator-gating that can run
 * while a tenant team is set must flip to the null team, check, and restore. This is the one
 * place that logic lives so the operator gate (RequireRootRole), the auth
 * projection (AuthUserResource), and the admin-realm manifest
 * visibility all resolve Root identically.
 */
class CentralRoot
{
    public static function isRoot(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId(null);
            $registrar->forgetCachedPermissions();

            $userModel = config('auth.providers.users.model');

            return (bool) $userModel::find($user->getAuthIdentifier())?->hasRole('Root');
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
