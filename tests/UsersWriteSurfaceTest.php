<?php

use Splicewire\Beam\Accounts\Actions\LogInAs;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Ops\LogInAsUser;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Particle\OperationKind;

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
    $op = LogInAsUser::operation();

    expect($op->resource)->toBe('users')
        ->and($op->name)->toBe('login-as')
        ->and($op->kind)->toBe(OperationKind::Write)
        // The point of the rewrite: the subject is resolved from `{id}` against a model, not from a
        // demo-subject string. That is what made it expressible as an op at all.
        ->and($op->model)->toBe(BeamAccounts::userModel())
        // NOT an attribute literal. `Models\User` is pinned to the `central` connection and hosts
        // swap the model routinely, so a hardcoded class would resolve {id} against the wrong table
        // — which is exactly how this first failed.
        ->and($op->model)->not->toBe(Splicewire\Beam\Accounts\Models\User::class)
        // No `ability:` on purpose: a signed link is anonymous, and `ability` cannot express that.
        ->and($op->ability)->toBeNull();
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
