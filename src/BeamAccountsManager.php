<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;

/**
 * The beam-accounts instance — the package's own host-resolution seams, fronted by
 * {@see BeamAccounts}.
 *
 * This is where `src/helpers.php` went. That file autoloaded five namespaced functions through
 * composer's `files` entry, all but one a config read, behind a single
 * `if (! function_exists(...))` guard that tested only the FIRST name — so a host
 * that defined any of the other four got either a redeclare fatal or four silently-missing
 * functions. Methods on a bound instance have no such guard to get wrong, and they are
 * `swap()`-able in a test where a global function never was.
 *
 * Every method here answers the same question — *which host class/scope does this satellite mean?*
 * — so the package can stay host-agnostic (ADR-0138: the host binds its own target-resolution
 * behind a port). The demo affordances are a separate subject with a separate facade
 * ({@see BeamDemoManager}); they are development-only, and mixing them in here would put a
 * never-in-production surface on the package's production front door.
 */
class BeamAccountsManager
{
    /**
     * The satellite's Authenticatable model class.
     */
    public function userModel(): string
    {
        return config('beam.accounts.user_model')
            ?: config('auth.providers.users.model')
            ?: User::class;
    }

    /**
     * The host's tenant-side (per-tenant replica) user model class.
     *
     * The base User (a SyncMaster) names its tenant counterpart through this seam rather than
     * importing a host `App\Models\*` class directly. A host that syncs users into tenant schemas
     * sets `beam.accounts.tenant_user_model`; unset falls back to the framework Authenticatable as
     * a safe null-object.
     */
    public function tenantUserModel(): string
    {
        return config('beam.accounts.tenant_user_model')
            ?: User::class;
    }

    /**
     * The session guard the account surface runs on.
     */
    public function guard(): string
    {
        return config('beam.accounts.guard', 'web');
    }

    /**
     * The personal-access-token model the account Tokens resource reads (Frame OS ticket 20).
     *
     * A host with a bespoke PAT model — its own connection (`central`), a uuid key, extra columns —
     * binds `beam.accounts.tokens.model`. Unset falls back to the package's own
     * {@see PersonalAccessToken}.
     */
    public function tokenModel(): string
    {
        return config('beam.accounts.tokens.model')
            ?: PersonalAccessToken::class;
    }

    /**
     * The team the account team-admin resources (Members / Invitations) scope to for the acting
     * request (Frame OS ticket 20).
     *
     * DOMAIN-NEUTRAL default: the authenticated user's current-or-personal team. A host whose
     * "active team" is a different notion — tower's per-request TENANT — binds a resolver
     * `beam.accounts.teams.resolver` (a `(): ?object` callable returning the scope object whose
     * `getKey()` is the `team_id`/`tenant_id` the invitations/memberships belong to). Unset falls
     * back to the current user's team.
     */
    public function currentTeam(): ?object
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

    /**
     * The central-Root check, flip-safe (admin-redesign ticket 02, Q1 — the `centralUserIsRoot()`
     * null-team footgun).
     *
     * Roles are team-scoped by `tenant_id` (spatie teams). In tenant context the current
     * permissions team is the tenant, so a bare `hasRole('Root')` sees only the *tenant* role and
     * misses Root — which is assigned on the central (null) team. Any operator-gating that can run
     * while a tenant team is set must flip to the null team, check, and restore. This is the one
     * place that logic lives, so the operator gate (`RequireRootRole`), the auth projection
     * ({@see Data\AuthUserData}), and the admin-realm manifest visibility all resolve Root
     * identically.
     */
    public function isRoot(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId(null);
            $registrar->forgetCachedPermissions();

            // Deliberately `config('auth.providers.users.model')`, NOT `$this->userModel()` —
            // carried over verbatim from the moved `Support\CentralRoot`. The two diverge when a
            // host sets `beam.accounts.user_model`, and reconciling them is a behaviour change
            // this move is not the place to make.
            $userModel = config('auth.providers.users.model');

            return (bool) $userModel::find($user->getAuthIdentifier())?->hasRole('Root');
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
            $registrar->forgetCachedPermissions();
        }
    }
}
