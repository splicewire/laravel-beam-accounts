<?php

namespace Splicewire\Beam\Accounts\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Impersonation\Impersonation;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;

/**
 * Impersonation START — assume a customer's identity, audited.
 *
 * The op half of the lift (particle-identity-resources ticket 03); {@see Impersonation} is the
 * mechanism and {@see UserPolicy::impersonate()} is the subject-side rule.
 *
 * TWO GATES, and they answer different questions:
 *  - `ability:` gates the ACTOR — may this principal impersonate at all? Host-supplied, because the
 *    operator entitlement is the host's vocabulary (audiostud gates on `bypass-marquee`). Defaults
 *    to `entitlement:os.operate`, the estate's operator key.
 *  - {@see UserPolicy::impersonate()} gates the SUBJECT — may THIS user be impersonated? Not
 *    yourself, and never another staff account.
 *
 * Imperative rather than `#[ParticleOp]` for the reason {@see LogInAsUser} documents at length: an
 * attribute cannot read config, and `model:` must be `BeamAccounts::userModel()` — `Models\User` is
 * pinned to the `central` connection and hosts routinely subclass it.
 *
 * `$resource` is a parameter because hosts mount this on different keys: the package default is
 * `users`, audiostud has its own `operator-customers` admin resource and mounts it there. One
 * declaration, either key.
 */
class ImpersonateUser
{
    public static function operation(string $resource = 'users'): ParticleOperation
    {
        return new ParticleOperation(
            resource: $resource,
            name: 'impersonate',
            kind: OperationKind::Write,
            model: BeamAccounts::userModel(),
            handle: self::handle(...),
            ability: config('beam.accounts.impersonation.ability', 'entitlement:os.operate'),
            abilityModel: BeamAccounts::userModel(),
        );
    }

    public static function handle(Model $user, Request $request, mixed $actor = null): mixed
    {
        $actor ??= $request->user();

        abort_if($actor === null, 403, 'Not authenticated.');

        // Self gets its own 400, ahead of the policy, because the two refusals mean different
        // things and both originals said so: impersonating yourself is a NONSENSICAL request
        // (400), while impersonating a peer operator is a FORBIDDEN one (403). The policy answers
        // both as one boolean — correct as a gate, but it cannot carry two status codes, so the
        // distinction is restated here rather than lost.
        abort_if(
            (string) $actor->getAuthIdentifier() === (string) $user->getKey(),
            400,
            'You cannot impersonate yourself.',
        );

        abort_if(
            ! Gate::forUser($actor)->allows('impersonate', $user),
            403,
            'This account cannot be impersonated.',
        );

        app(Impersonation::class)->start($user, $actor);

        // Route-name keyed (survives URI moves), config-driven because the package cannot know where
        // a host's customer-facing home is. audiostud lands on `app.home`, numero on `/dashboard`.
        return $request->expectsJson()
            ? ['data' => ['impersonating' => (string) $user->getKey()]]
            : redirect()->to(self::landing());
    }

    private static function landing(): string
    {
        $target = config('beam.accounts.impersonation.start_redirect', '/');

        return \Illuminate\Support\Facades\Route::has($target) ? route($target) : $target;
    }
}
