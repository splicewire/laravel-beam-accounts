<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\Impersonation\Impersonation;
use Splicewire\Beam\Accounts\Models\ImpersonationEvent;
use Splicewire\Beam\Accounts\Ops\ImpersonateUser;
use Splicewire\Beam\Accounts\Ops\StopImpersonating;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;

beforeEach(function () {
    Schema::create(Beam::table('impersonation_events'), function (Blueprint $table): void {
        $table->id();
        $table->string('actor_id');
        $table->string('subject_id')->nullable();
        $table->string('action');
        $table->timestamp('created_at')->nullable();
    });

    $this->operator = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'x']);
    $this->customer = User::create(['name' => 'Cust', 'email' => 'cust@example.test', 'password' => 'x']);

    $this->impersonation = app(Impersonation::class);
});

// ── THE HEADLINE REQUIREMENT ──────────────────────────────────────────────────────────────────
//
// While impersonating, the actor IS the customer. A staff-gated stop therefore traps the operator
// in the impersonated session with no way back. The stop op must carry NO ability; its guard is the
// session stash. audiostud had this right and the lift must not lose it.

it('declares no ability on stop-impersonating, so a staff gate can never trap the operator', function () {
    expect(StopImpersonating::operation()->ability)->toBeNull();
});

it('gates the START op on an ability, unlike stop', function () {
    expect(ImpersonateUser::operation()->ability)->not->toBeNull();
});

it('lets the impersonated session stop, even though it is no longer staff', function () {
    Auth::login($this->operator);
    $this->impersonation->start($this->customer, $this->operator);

    // The acid test: the acting principal is now the CUSTOMER, who holds no staff entitlement.
    expect(Auth::id())->toBe($this->customer->getKey())
        ->and($this->impersonation->isImpersonating())->toBeTrue();

    $this->impersonation->stop();

    expect(Auth::id())->toBe($this->operator->getKey())
        ->and($this->impersonation->isImpersonating())->toBeFalse();
});

// ── The service ───────────────────────────────────────────────────────────────────────────────

it('writes an append-only audit row for both start and stop', function () {
    Auth::login($this->operator);

    $this->impersonation->start($this->customer, $this->operator);
    $this->impersonation->stop();

    $events = ImpersonationEvent::orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->action)->toBe('start')
        ->and((string) $events[0]->actor_id)->toBe((string) $this->operator->getKey())
        ->and((string) $events[0]->subject_id)->toBe((string) $this->customer->getKey())
        ->and($events[1]->action)->toBe('stop')
        ->and((string) $events[1]->actor_id)->toBe((string) $this->operator->getKey());
});

it('refuses to stop when nothing is being impersonated', function () {
    Auth::login($this->operator);

    expect(fn () => $this->impersonation->stop())
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('holds string keys so a uuid-keyed and a bigint-keyed host share one shape', function () {
    // audiostud keys actor_id as foreignUuid; numero as foreignId. The package table cannot be both,
    // so it is strings — the same choice view_requests already makes for its morph keys.
    Auth::login($this->operator);
    $this->impersonation->start($this->customer, $this->operator);

    expect(ImpersonationEvent::first()->actor_id)->toBeString();
});

// ── The policy ────────────────────────────────────────────────────────────────────────────────

it('refuses self-impersonation', function () {
    expect((new UserPolicy)->impersonate($this->operator, $this->operator))->toBeFalse();
});

it('refuses to impersonate another staff account', function () {
    $policy = new UserPolicy;

    // Staff is the `entitlement:os.operate` key — NOT numero's retired is_staff column.
    Gate::define('entitlement:os.operate', fn ($user) => $user->is($this->customer));

    expect($policy->impersonate($this->operator, $this->customer))->toBeFalse();
});

it('admits an ordinary customer', function () {
    expect((new UserPolicy)->impersonate($this->operator, $this->customer))->toBeTrue();
});

it('reads entitlement:os.operate and nothing else, even if a host tries to redirect it', function () {
    // The inverse of the test this replaces. `staff_ability` was a migration bridge for hosts still
    // on the retired `is_staff` column; it is gone (particle-identity-resources ticket 04) and must
    // not come back — a per-host staff notion makes this refusal mean something different per host,
    // and it fails OPEN when it is wrong. A stale config key must be inert, not honoured.
    config()->set('beam.accounts.impersonation.staff_ability', 'bypass-marquee');
    Gate::define('bypass-marquee', fn ($user) => $user->is($this->customer));

    // `bypass-marquee` says the customer IS staff. The policy does not ask it, so the customer stays
    // impersonatable.
    expect((new UserPolicy)->impersonate($this->operator, $this->customer))->toBeTrue();

    // ...and the one key it does ask still refuses.
    Gate::define('entitlement:os.operate', fn ($user) => $user->is($this->customer));

    expect((new UserPolicy)->impersonate($this->operator, $this->customer))->toBeFalse();
});
