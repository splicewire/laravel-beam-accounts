<?php

namespace Splicewire\Beam\Accounts\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;

/**
 * Assume a demo subject's identity on the session guard. The single wrapped operation
 * behind both the `splicewire:beam:accounts:login-as` command and the signed login-as route. An engine
 * affordance (gated by demo.enabled), not a per-satellite fork.
 */
class LogInAs
{
    public function __invoke(string $subject): Authenticatable
    {
        abort_unless(BeamDemo::enabled(), 403, 'Demo login is disabled in this environment.');

        if (! BeamDemo::has($subject)) {
            throw ValidationException::withMessages([
                'subject' => "Unknown demo subject [{$subject}]. Expected one of: ".implode(', ', BeamDemo::keys()),
            ]);
        }

        $user = BeamAccounts::userModel()::query()->where('email', BeamDemo::email($subject))->firstOrFail();

        Auth::guard(BeamAccounts::guard())->login($user);

        return $user;
    }
}
