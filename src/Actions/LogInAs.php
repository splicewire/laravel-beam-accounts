<?php

namespace Splicewire\Beam\Accounts\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

use function Splicewire\Beam\Accounts\accountGuard;
use function Splicewire\Beam\Accounts\accountUserModel;

use Splicewire\Beam\Accounts\Support\Demo;

/**
 * Assume a demo subject's identity on the session guard. The single wrapped operation
 * behind both the `splicewire:beam:account-login-as` command and the signed login-as route. An engine
 * affordance (gated by demo.enabled), not a per-satellite fork.
 */
class LogInAs
{
    public function __invoke(string $subject): Authenticatable
    {
        abort_unless(Demo::enabled(), 403, 'Demo login is disabled in this environment.');

        if (! Demo::has($subject)) {
            throw ValidationException::withMessages([
                'subject' => "Unknown demo subject [{$subject}]. Expected one of: ".implode(', ', Demo::keys()),
            ]);
        }

        $user = accountUserModel()::query()->where('email', Demo::email($subject))->firstOrFail();

        Auth::guard(accountGuard())->login($user);

        return $user;
    }
}
