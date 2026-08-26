<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Actions\LogInAs;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * Assume a user's identity — the ONE implementation of login-as, reached as
 * `users/{id}/op/login-as`.
 *
 * This replaces the bespoke `LoginAsController` + its `account/login-as/{subject}` route. login-as
 * could not be an op while it was keyed by a demo SUBJECT STRING, since an op resolves `{id}`
 * against a `model:` — but that indirection was never load-bearing: a demo subject IS a user,
 * reached by `BeamDemo::email($key)`. Keying off the user id makes this an ordinary operation, and
 * the artisan command now mints a signed link straight at this route.
 *
 * TWO TRANSPORTS, one operation. The handler returns a REDIRECT for a browser (the signed-link
 * affordance — landing on a JSON blob would be useless) and {@see AuthUserData} for a JSON caller.
 * beam supports this natively: `ParticleOperationController::finish()` envelopes a `Data` return and
 * passes anything else through untouched.
 *
 * ## TWO credentials, and both are now DECLARED (api-surface-coherence ticket 95)
 *
 * This operation has TWO legitimate callers holding DIFFERENT credentials:
 *
 *  - an anonymous holder of a short-lived SIGNED LINK — the whole point of the affordance is that
 *    you are not yet logged in as anybody; and
 *  - an authenticated central Root operator, invoking it as an ordinary API/MCP call.
 *
 * `ability:` can express only the second, and until beam grew a `signed:` slot that made this op the
 * estate's exemplar of the gap: declaring `ability: 'loginAs'` would have 403'd the signed link
 * before the handler ever ran, and declaring `input: false` would have 422'd it (the signer appends
 * `?expires=…&signature=…`, which `rejectInput()` read as caller payload). So BOTH slots shipped
 * undeclared and the whole gate was hand-rolled here, in a private `assertMayAssume()`.
 *
 * `signed: true` is what closed it. The two credentials are orthogonal, so they get orthogonal
 * slots: `ability: 'loginAs'` states the operator half — resolved through {@see UserPolicy::loginAs()}
 * like any other policy question — and `signed: true` states that a validly-signed URL admits on its
 * own, satisfying that ability rather than competing with it. `input: false` follows for free, because
 * `signed:` is also what makes the signer's two reserved parameters the FRAMEWORK's rather than the
 * caller's. Three declarations, no hand-rolled gate, and `users.login-as` stops being one of the two
 * operations blocking the `input:` `null` ⇒ `false` flip.
 *
 * ⚠️ **The `local`/`testing` bypass is gone, and that is a security fix rather than a casualty.** The
 * retired hand-rolled gate returned early in those environments so `GET users/{id}/op/login-as` could
 * be typed by hand — which means that on any host running `APP_ENV=local`, an unauthenticated request
 * could assume ANY user's identity by id. A framework gate has no environment carve-out and must not
 * grow one; the replacement affordance for a developer is the command that already exists,
 * `splicewire:beam:accounts:login-as`, which mints exactly the signed link this op now declares.
 *
 * ## Why this is an imperative ParticleOperation and not a `#[ParticleOp]` class
 *
 * An attribute cannot read config, and `model:` on a users operation MUST. `Models\User` is pinned
 * to the `central` connection, and hosts routinely subclass it as their own `App\Models\User` —
 * {@see UserData}'s docblock calls that seam load-bearing rather than theoretical. An attribute
 * literal would hardcode the package model AND its connection, so the op would resolve `{id}`
 * against the wrong table on every host that swaps the model. Registering imperatively lets `model:`
 * come from `BeamAccounts::userModel()` at registration time — the same reason
 * `Sharing::ledgerResources()` builds its revoke op as a runtime object in this package.
 */
class LogInAsUser
{
    /**
     * The runtime declaration, with the host's configured user model resolved in.
     */
    public static function operation(): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'users',
            name: 'login-as',
            kind: OperationKind::Write,
            model: BeamAccounts::userModel(),
            handle: self::handle(...),
            output: AuthUserData::class,
            // Declared, both of them, and only because `signed:` exists. See the class docblock: this
            // op is the reason that slot was built, and the two lines below are the measurement that
            // it works — `ability:` gates the operator half without 403ing the signed link, and
            // `input: false` binds without 422ing it, because `expires`/`signature` are framework
            // parameters on a `signed:` op rather than caller payload.
            ability: 'loginAs',
            input: false,
            signed: true,
        );
    }

    public static function handle(Model $user, Request $request, mixed $actor = null): mixed
    {
        // The ONLY check left here, and it is an ENVIRONMENT gate rather than an authorization one —
        // which is why it stays in the handler and carries its own reason. Authorization itself is
        // now entirely declared (`ability:` + `signed:`) and runs in the framework before this point.
        abort_unless(BeamDemo::enabled(), 403, 'Demo login is disabled in this environment.');

        app(LogInAs::class)->asUser($user);

        // Session fixation: the pre-login session id must not survive the identity change. Guarded
        // because a token-authenticated JSON caller has no session at all.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $request->expectsJson()
            ? AuthUserData::fromUser($user->fresh(), $request->bearerToken())
            : redirect()->to(self::redirectFor($user));
    }

    /**
     * The designated operator demo subject lands on the operator shell when one is routed (its own
     * `bootOperatorShell()` default, or a host's `operator.home`) — the point of a different demo
     * subject per role is landing somewhere that shows what that role can reach, not the same
     * generic default every subject shares. Every other subject keeps `beam.accounts.demo.redirect`.
     *
     * Matched by EMAIL rather than by subject key, because the subject key is exactly what this op
     * no longer takes; `BeamDemo::email()` is the same mapping the seeder used to provision it.
     */
    private static function redirectFor(Model $user): string
    {
        $isOperator = $user->email === BeamDemo::email(BeamDemo::operatorKey());

        return $isOperator && Route::has('operator.home')
            ? route('operator.home')
            : config('beam.accounts.demo.redirect', '/');
    }
}
