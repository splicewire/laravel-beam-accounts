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
     * Resolve the session guard the account surface runs on.
     */
    function accountGuard(): string
    {
        return config('beam-accounts.guard', 'web');
    }
}
