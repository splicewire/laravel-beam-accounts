<?php

namespace Splicewire\Beam\Accounts;

use Illuminate\Foundation\Auth\User;

if (! function_exists('Splicewire\Beam\Accounts\accountUserModel')) {
    /**
     * Resolve the satellite's Authenticatable model class.
     */
    function accountUserModel(): string
    {
        return config('splicewire.account.user_model')
            ?: config('auth.providers.users.model')
            ?: User::class;
    }

    /**
     * Resolve the session guard the account surface runs on.
     */
    function accountGuard(): string
    {
        return config('splicewire.account.guard', 'web');
    }
}
