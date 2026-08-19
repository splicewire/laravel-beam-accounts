<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Impersonation\Impersonation;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * Impersonation STOP — restore the operator's own session.
 *
 * ## This op declares NO `ability:`, and that is the single most important line in the feature
 *
 * While impersonating, the acting principal IS the customer. They hold none of the staff
 * entitlements that authorized the swap — so ANY ability on this op, or any staff middleware on its
 * route, locks the operator inside the impersonated session with no way back. The guard is the
 * SESSION STASH instead: {@see Impersonation::stop()} aborts 403 when nothing is stashed, which is
 * both necessary and sufficient — you can only stop an impersonation you are actually in.
 *
 * audiostud got this right and its comment says so; numero's controller likewise sits outside the
 * staff gate. The lift preserves it, and `tests/ImpersonationTest.php` asserts it directly rather
 * than trusting prose, because it is exactly the kind of property a later "tighten the gates" pass
 * would break in good faith.
 *
 * Mounting note for hosts: put this route in a group gated by `auth` ONLY, never in the operator/
 * staff group next to {@see ImpersonateUser}. The two ops are siblings that must not share a gate.
 *
 * The `{id}` is structurally required by the operation route shape but carries no meaning here —
 * what gets restored is whatever the session stashed, not whatever id was addressed. The handler
 * therefore ignores its subject deliberately.
 */
class StopImpersonating
{
    public static function operation(string $resource = 'users'): ParticleOperation
    {
        return new ParticleOperation(
            resource: $resource,
            name: 'stop-impersonating',
            kind: OperationKind::Write,
            model: BeamAccounts::userModel(),
            handle: self::handle(...),
            // ability: DELIBERATELY ABSENT — see the class docblock. Do not add one.
        );
    }

    public static function handle(Model $user, Request $request, mixed $actor = null): mixed
    {
        app(Impersonation::class)->stop();

        return $request->expectsJson()
            ? ['data' => ['impersonating' => null]]
            : redirect()->to(self::landing());
    }

    /** Where the restored operator lands — their own console, not the customer's. */
    private static function landing(): string
    {
        $target = config('beam.accounts.impersonation.stop_redirect', '/');

        return Route::has($target) ? route($target) : $target;
    }
}
