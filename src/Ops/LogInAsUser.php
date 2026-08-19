<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
 * ## Why there is no `ability:` slot, which is a deliberate departure
 *
 * This operation has TWO legitimate callers holding DIFFERENT credentials:
 *
 *  - an anonymous holder of a short-lived SIGNED LINK — the whole point of the affordance is that
 *    you are not yet logged in as anybody; and
 *  - an authenticated central Root operator, invoking it as an ordinary API/MCP call.
 *
 * `ability:` can express only the second. Declaring `ability: 'loginAs'` would 403 the signed link
 * before the handler ever ran — the exact affordance this op exists to serve. So the gate is stated
 * here instead, as one predicate covering both credentials, and {@see UserPolicy::loginAs()} is what
 * the privileged half consults so the authenticated path still resolves through the policy rather
 * than a second hand-rolled rule.
 *
 * The general shape — "a validly-signed request is itself a credential" — is a gap in beam's
 * `AbilityResolver`, which only ever asks about an actor. Worth closing there; until it is, this is
 * the honest place for the check, and it is called out rather than buried.
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
        );
    }

    public static function handle(Model $user, Request $request, mixed $actor = null): mixed
    {
        abort_unless(BeamDemo::enabled(), 403, 'Demo login is disabled in this environment.');

        self::assertMayAssume($user, $request, $actor);

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
     * Either credential admits: a valid signature on the URL, or a principal the {@see UserPolicy}
     * lets assume this identity. Local/testing skips both, preserving the bare
     * `GET users/{id}/op/login-as` a developer types by hand — the same carve-out the retired
     * controller made, and the reason the suite can drive this without minting signatures.
     */
    private static function assertMayAssume(Model $user, Request $request, mixed $actor): void
    {
        if (app()->environment('local', 'testing')) {
            return;
        }

        if ($request->hasValidSignature()) {
            return;
        }

        abort_unless(
            $actor !== null && Gate::forUser($actor)->allows('loginAs', $user),
            403,
            'This demo login link is invalid or has expired.',
        );
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
