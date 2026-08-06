<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;

if (! function_exists('Splicewire\Beam\Accounts\accountUserModel')) {
    /**
     * Resolve the satellite's Authenticatable model class.
     */
    function accountUserModel(): string
    {
        return config('beam.accounts.user_model')
            ?: config('auth.providers.users.model')
            ?: User::class;
    }

    /**
     * Resolve the host's tenant-side (per-tenant replica) user model class.
     *
     * The base User (a SyncMaster) names its tenant counterpart via this seam rather
     * than importing a host `App\Models\*` class directly — keeping beam-accounts
     * host-agnostic (ADR-0138: the host binds its own target-resolution behind a port).
     * A host that syncs users into tenant schemas sets `beam.accounts.tenant_user_model`;
     * unset falls back to the framework Authenticatable as a safe null-object.
     */
    function accountTenantUserModel(): string
    {
        return config('beam.accounts.tenant_user_model')
            ?: User::class;
    }

    /**
     * Resolve the session guard the account surface runs on.
     */
    function accountGuard(): string
    {
        return config('beam.accounts.guard', 'web');
    }

    /**
     * Resolve the personal-access-token model the account Tokens resource reads (Frame OS ticket 20).
     *
     * A host with a bespoke PAT model — its own connection (`central`), a uuid key, extra
     * columns — binds `beam.accounts.tokens.model`. Unset falls back to the package's own
     * {@see PersonalAccessToken}.
     */
    function accountTokenModel(): string
    {
        return config('beam.accounts.tokens.model')
            ?: PersonalAccessToken::class;
    }

    /**
     * Resolve the team the account team-admin resources (Members / Invitations) scope to for the
     * acting request (Frame OS ticket 20).
     *
     * DOMAIN-NEUTRAL default: the authenticated user's current-or-personal team. A host whose
     * "active team" is a different notion — tower's per-request TENANT — binds a resolver
     * `beam.accounts.teams.resolver` (a `(): ?object` callable returning the scope object whose
     * `getKey()` is the `team_id`/`tenant_id` the invitations/memberships belong to). Unset falls
     * back to the current user's team.
     */
    function accountCurrentTeam(): ?object
    {
        $resolver = config('beam.accounts.teams.resolver');

        if (is_callable($resolver)) {
            return $resolver();
        }

        $user = Auth::user();

        return method_exists($user, 'currentTeamOrPersonal')
            ? $user->currentTeamOrPersonal()
            : null;
    }
}
