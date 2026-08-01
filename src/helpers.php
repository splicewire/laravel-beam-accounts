<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Foundation\Auth\User;

if (! function_exists('Splicewire\Beam\Accounts\accountUserModel')) {
    /**
     * Resolve the satellite's Authenticatable model class.
     */
    function accountUserModel(): string
    {
        return config('beam-accounts.user_model')
            ?: config('auth.providers.users.model')
            ?: User::class;
    }

    /**
     * Resolve the host's tenant-side (per-tenant replica) user model class.
     *
     * The base User (a SyncMaster) names its tenant counterpart via this seam rather
     * than importing a host `App\Models\*` class directly — keeping beam-accounts
     * host-agnostic (ADR-0138: the host binds its own target-resolution behind a port).
     * A host that syncs users into tenant schemas sets `beam-accounts.tenant_user_model`;
     * unset falls back to the framework Authenticatable as a safe null-object.
     */
    function accountTenantUserModel(): string
    {
        return config('beam-accounts.tenant_user_model')
            ?: User::class;
    }

    /**
     * Resolve the session guard the account surface runs on.
     */
    function accountGuard(): string
    {
        return config('beam-accounts.guard', 'web');
    }
}
