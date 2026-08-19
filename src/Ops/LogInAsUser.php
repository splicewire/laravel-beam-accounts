<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Actions\LogInAs;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * Assume a user's identity — the declared `#[ParticleOp]` form of the demo login-as affordance,
 * mounted at `POST users/{id}/op/login-as`.
 *
 * WHY THIS EXISTS ALONGSIDE {@see LogInAs}: the action is keyed by a demo SUBJECT STRING
 * (`'admin'`, `'solo'`), which is why login-as could not previously be an op at all — an op resolves
 * a `{id}` against a `model:`, and a subject key is not an id. But the indirection was never
 * load-bearing: a demo subject IS a user, reached by `BeamDemo::email($key)`. Keying off the user id
 * instead makes this an ordinary operation on the `users` resource, and the whole demo-key lookup
 * drops out of the request path.
 *
 * The signed `GET account/login-as/{subject}` route stays — it is what the
 * `splicewire:beam:accounts:login-as` command mints and what a human clicks, and a signed GET is the
 * right shape for that. This is the transport-neutral twin: registry-reachable, permission-bearing,
 * codegen-visible.
 *
 * TWO GATES, deliberately separate:
 *  - `ability: 'loginAs'` → {@see UserPolicy::loginAs()}, central Root only. An AUTHORIZATION
 *    question, so it lives on the policy where every other authorization question lives.
 *  - demo-enabled → checked here, not in the policy. "Are the demo affordances live" is an
 *    ENVIRONMENT question, and answering it here lets the op 403 with a reason a caller can act on
 *    rather than an opaque policy denial.
 */
#[ParticleOp(
    resource: 'users',
    name: 'login-as',
    kind: OperationKind::Write,
    model: \Splicewire\Beam\Accounts\Models\User::class,
    ability: 'loginAs',
    output: AuthUserData::class,
)]
class LogInAsUser
{
    public static function handle(Model $user, Request $request, mixed $actor = null): AuthUserData
    {
        abort_unless(BeamDemo::enabled(), 403, 'Demo login is disabled in this environment.');

        $assumed = app(LogInAs::class)->asUser($user);

        return AuthUserData::fromUser($assumed, $request->bearerToken());
    }
}
