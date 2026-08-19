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

        return $this->asUser($user);
    }

    /**
     * Log in as an already-resolved user, skipping the demo-subject lookup.
     *
     * The subject-keyed `__invoke` above is one particular way to NAME a user; this is the act
     * itself. Split out so {@see Ops\LogInAsUser} — which resolves its subject from a `{id}` like
     * every other particle operation — reuses the session-guard login rather than restating it.
     * Deliberately carries NO demo gate of its own: both callers gate before they get here, and a
     * silent second check would make the reason for a 403 ambiguous.
     */
    public function asUser(Authenticatable $user): Authenticatable
    {
        Auth::guard(BeamAccounts::guard())->login($user);

        return $user;
    }
}
