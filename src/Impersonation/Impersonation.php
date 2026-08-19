<?php

namespace Splicewire\Beam\Accounts\Impersonation;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\ImpersonationEvent;
use Splicewire\Beam\Accounts\Ops\StopImpersonating;

/**
 * Operator impersonation — the session stash, the identity swap, and the audit write, in one place.
 *
 * Lifted from audiostud and numero, which had implemented this independently and near-identically
 * (particle-identity-resources ticket 03): the same `'operator.impersonator_id'` session key
 * literal, the same abort messages, byte-identical audit models. This is the deduplication.
 *
 * NOT the same thing as demo login-as, and deliberately not merged with it. `login-as` targets fixed
 * seeded demo subjects, has no audit trail and no return path; this is the general, audited
 * mechanism over arbitrary real users. They share exactly one thing — the session-guard login — and
 * that is `LogInAs::asUser()`, which is already split out for the purpose.
 *
 * ## The return path is the load-bearing part
 *
 * `start()` stashes the operator's own id BEFORE swapping the session. That stash is the only way
 * back: once swapped, the acting principal is the customer and holds none of the staff entitlements
 * that authorized the swap in the first place. So `stop()` is guarded by the STASH, never by an
 * ability — see {@see StopImpersonating}, which declares no `ability:` for exactly this reason. A
 * staff-gated stop traps the operator in the impersonated session with no way out.
 */
class Impersonation
{
    /**
     * The session key holding the operator's id while impersonating.
     *
     * Deliberately the SAME literal both hosts already used, so their in-flight sessions survive the
     * cutover instead of stranding whoever was mid-impersonation when the code shipped.
     */
    public const SESSION_KEY = 'operator.impersonator_id';

    /**
     * Assume the subject's identity, stashing the actor's id for the return trip.
     *
     * Authorization is the CALLER's — {@see \Splicewire\Beam\Accounts\Authorization\UserPolicy::impersonate()}
     * states the rule and the op enforces it. This method is the mechanism, so a host with a
     * different gate can reuse the mechanism without inheriting the gate.
     */
    public function start(Authenticatable $subject, Authenticatable $actor): void
    {
        $this->audit('start', $actor->getAuthIdentifier(), $subject->getKey());

        session()->put(self::SESSION_KEY, (string) $actor->getAuthIdentifier());

        Auth::guard(BeamAccounts::guard())->login($subject);

        // Session fixation: the pre-swap session id must not survive the identity change. Note this
        // runs AFTER the stash is written — `regenerate()` migrates session data by default, so the
        // stash carries across, which is what makes the return trip possible at all.
        session()->regenerate();
    }

    /**
     * Restore the operator's own session. Aborts 403 when nothing is stashed — there is no identity
     * to return to, and silently succeeding would leave the caller believing it had stopped.
     */
    public function stop(): void
    {
        $actorId = session()->pull(self::SESSION_KEY);

        abort_if($actorId === null, 403, 'Not impersonating.');

        $this->audit('stop', $actorId, Auth::id());

        Auth::guard(BeamAccounts::guard())->loginUsingId($actorId);

        session()->regenerate();
    }

    /** Whether the current session is an impersonated one — the banner predicate both hosts need. */
    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /** The impersonating operator's id, or null when this is an ordinary session. */
    public function impersonatorId(): int|string|null
    {
        return session()->get(self::SESSION_KEY);
    }

    private function audit(string $action, int|string|null $actorId, int|string|null $subjectId): void
    {
        ImpersonationEvent::create([
            'actor_id' => (string) $actorId,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'action' => $action,
        ]);
    }
}
