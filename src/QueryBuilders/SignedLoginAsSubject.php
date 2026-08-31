<?php

namespace Splicewire\Beam\Accounts\QueryBuilders;

use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Accounts\Ops\LogInAsUser;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/**
 * The one narrow hole in {@see UsersQuery}'s guest-sees-nothing rule: a request carrying the
 * server's OWN signature over `users/{id}/op/login-as` may resolve exactly the `{id}` that
 * signature covers, and nothing else.
 *
 * ## The defect this closes (beam-facade ticket 172(b))
 *
 * {@see LogInAsUser} declares `signed: true`, so a validly-signed request is admitted as a
 * credential in its own right. But ADMISSION is not RESOLUTION, and the two run in that order:
 * `ParticleOperationController::invoke()` resolves the operation's SUBJECT *before* it evaluates
 * the signature, through the resource's row-level `scope` closure. For `users` that closure is
 * {@see UserData::scope()} ⇒ {@see UsersQuery::scopeToSharedTeams()}, whose fail-safe for a null
 * actor is `whereRaw('1 = 0')`.
 *
 * A signed login-as link is anonymous BY CONSTRUCTION — the whole point of the affordance is that
 * you are not yet logged in as anybody — so every such link resolved against
 * `select * from "users" where 1 = 0 and "users"."id" = …` and 404'd. The link could never find the
 * subject it existed to become, because the only caller who could pass the scope is the user the
 * link exists to produce. Both documented ways into an authenticated page were down.
 *
 * ## Why this is not "drop the scope for guests"
 *
 * The fail-safe is right and stays. What this adds is not a WIDER answer to the visibility question
 * ("which principals may this actor see?") — it is a DIFFERENT question, whose answer the request
 * carries: *the server itself named this row, in a signature it can verify.* Four conditions, all
 * required, and each one is load-bearing:
 *
 *   1. the request is on a **particle operation route** whose `resource` default is `users` and
 *      whose `name` default is `login-as` — read off the route's own defaults, so no other mount on
 *      the users resource (the list, the per-record read, any other operation) is touched;
 *   2. `hasValidSignature()` — the real cryptographic check against `APP_KEY`, including expiry.
 *      Not "has a `signature` query parameter";
 *   3. the admitted row is the **`{id}` from that same signed URL**. The signature covers the whole
 *      URL, so an attacker cannot keep a valid signature and swap the id;
 *   4. exactly **one** row: `whereKey()`, never an `orWhere` onto the actor's own visibility.
 *
 * So the widest thing a guest gains is the ability to resolve the single user a valid signed
 * login-as link already entitles them to BECOME. That is strictly less than the link itself grants,
 * which means this adds no reach at all — it only stops the resolution step from refusing a
 * credential the authorization step was built to accept.
 *
 * An unsigned guest is unchanged: no signature ⇒ this returns `null` ⇒ `1 = 0`, and the request
 * 404s exactly as it does today. That is measured, not asserted — see the guest-visibility cases in
 * `tests/DemoLoginAsTest.php`, all of which run with the gate CLOSED (no `Gate::before`).
 *
 * ## Why it is consulted BEFORE the host's `beam.accounts.users.scope` seam
 *
 * That seam answers the visibility question, and a host binding it cannot know about signatures —
 * it is handed `(Builder, ?Authenticatable)` and nothing else. Running it first would let an
 * ordinary host visibility rule silently disable the package's own login-as affordance, which is
 * the failure mode this ticket exists to remove. The branch is therefore ahead of the seam and
 * scoped so narrowly that there is nothing for the seam to have an opinion about.
 */
class SignedLoginAsSubject
{
    /** The particle resource key the login-as operation is mounted on. */
    public const RESOURCE = 'users';

    /** The operation name, as declared by {@see LogInAsUser}'s `#[ParticleOp]`. */
    public const OPERATION = 'login-as';

    /**
     * The user id a validly-signed login-as request names, or `null` for every other request.
     *
     * `null` is the answer for an unsigned request, a tampered or expired one, and any request that
     * is not on the login-as mount — so a caller can treat a non-null return as "the server signed
     * this exact row" without re-deriving any of the four conditions.
     */
    public static function id(?Request $request = null): ?string
    {
        $request ??= request();

        if (! $request instanceof Request) {
            return null;
        }

        $route = $request->route();

        if ($route === null) {
            return null;
        }

        $defaults = $route->defaults;

        if (($defaults[ParticleOperationController::RESOURCE] ?? null) !== self::RESOURCE) {
            return null;
        }

        if (($defaults[ParticleOperationController::NAME] ?? null) !== self::OPERATION) {
            return null;
        }

        // The cryptographic check, deliberately last: it is the expensive one, and the two route
        // conditions above are what make a valid signature on some OTHER signed route irrelevant
        // here.
        if (! $request->hasValidSignature()) {
            return null;
        }

        $id = $route->parameter('id');

        return $id === null ? null : (string) $id;
    }
}
