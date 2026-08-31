<?php

use Splicewire\Beam\Accounts\Actions\LogInAs;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Ops\LogInAsUser;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Subject\OperationSubjectModel;

beforeEach(function () {
    $this->policy = new UserPolicy;

    $this->ada = User::create([
        'name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234',
    ]);
    $this->grace = User::create([
        'name' => 'Grace', 'email' => 'grace@example.test', 'password' => 'password-1234',
    ]);
});

// ── UserPolicy: the gate that had to exist before `users` could widen `editable` ──────────────

it('lets a user edit themselves', function () {
    expect($this->policy->update($this->ada, $this->ada))->toBeTrue();
});

it('refuses a peer, even one the read scope would happily show', function () {
    // The read boundary is deliberately WIDER than the write boundary: sharing a team makes Grace
    // visible to Ada, and must not make her editable by Ada. This is the whole reason the policy
    // exists rather than leaning on the resource scope.
    expect($this->policy->update($this->ada, $this->grace))->toBeFalse();
});

it('refuses a guest', function () {
    expect($this->policy->update(null, $this->ada))->toBeFalse();
});

it('never admits a generic create', function () {
    expect($this->policy->create($this->ada))->toBeFalse();
});

it('holds the same self-or-root rule on delete, for a host that widens deletable', function () {
    expect($this->policy->delete($this->ada, $this->ada))->toBeTrue()
        ->and($this->policy->delete($this->ada, $this->grace))->toBeFalse();
});

it('gates login-as to root, so an ordinary user cannot assume an identity', function () {
    expect($this->policy->loginAs($this->ada, $this->grace))->toBeFalse();
});

// ── The login-as op ───────────────────────────────────────────────────────────────────────────

it('declares login-as as a write op on the users resource', function () {
    // Read the op the way the framework builds it — discover the ATTRIBUTE, then read the registry.
    // This is deliberately not a call to a factory method: `LogInAsUser` became attributed
    // (particle-operation-surface 18 retired `model:`, which was the one thing forcing it imperative),
    // and the assertions below are only meaningful if they run against the VO that
    // `AttributedParticleDiscovery` actually produces. Every slot must survive that translation —
    // `signed:` in particular was absent from it for two days, during which "cannot be signed" and
    // "declared unsigned" were the same reading.
    app(AttributedParticleDiscovery::class)->registerClass(LogInAsUser::class);

    $op = app(ParticleOperationRegistry::class)->get('users', 'login-as');
    $subjectModel = app(OperationSubjectModel::class);

    expect($op->resource)->toBe('users')
        ->and($op->name)->toBe('login-as')
        ->and($op->kind)->toBe(OperationKind::Write)
        // The point of the rewrite: the subject is resolved from `{id}` against a model, not from a
        // demo-subject string. That is what made it expressible as an op at all.
        //
        // Read through OperationSubjectModel rather than off `$op->model`, which this op no longer
        // declares (particle-operation-surface 18). The fact that matters is unchanged and is now
        // asserted where it actually lives: the `users` resource backs ConfiguredUserBacking, so the
        // subject model follows `BeamAccounts::userModel()` — NOT a hardcoded `Models\User`, which is
        // pinned to the `central` connection and is exactly how this first failed.
        ->and($subjectModel->for($op))->toBe(BeamAccounts::userModel())
        ->and($subjectModel->for($op))->not->toBe(Splicewire\Beam\Accounts\Models\User::class)
        // Both credentials are DECLARED (api-surface-coherence ticket 95). `ability:` states the
        // operator half; `signed:` states that a valid URL signature admits on its own, which is what
        // an anonymous signed-link holder holds and what `ability:` structurally cannot express. Before
        // that slot existed this op shipped `ability: null` and hand-rolled the whole gate.
        ->and($op->ability)->toBe('loginAs')
        ->and($op->signed)->toBeTrue()
        // Free once `signed:` is declared: `expires`/`signature` become framework parameters, so
        // `input: false` binds without 422ing the very link the op exists to serve.
        ->and($op->input)->toBeFalse()
        ->and($op->frameworkParameters())->toBe(['expires', 'signature']);
});

it('refuses to run when the demo affordances are off, whatever the policy says', function () {
    config()->set('beam.accounts.demo.enabled', false);

    expect(BeamDemo::enabled())->toBeFalse();

    // Environment gate, not an authorization gate — checked in the op so the 403 carries a reason.
    expect(fn () => LogInAsUser::handle($this->grace, request()))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('logs in as the resolved user when demo is live', function () {
    config()->set('beam.accounts.demo.enabled', true);

    LogInAsUser::handle($this->grace, request());

    expect(auth()->guard('web')->user()?->getKey())->toBe($this->grace->getKey());
});

// ── The action split the op leans on ──────────────────────────────────────────────────────────

it('logs in an already-resolved user without a demo-subject lookup', function () {
    app(LogInAs::class)->asUser($this->grace);

    expect(auth()->guard('web')->user()?->getKey())->toBe($this->grace->getKey());
});
