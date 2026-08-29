<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;

/**
 * A role string the {@see Role} enum does not know must DENY, never fatal.
 *
 * `Role` is closed — `owner|admin|member` — but the role column is a plain `string` at every host
 * that stores one, so the database is free to hold a value the enum has never heard of, and does:
 * measured 2026-08-29 at `~/Herd/splicewire-app`, **17 of 42 `tenant_users` rows carry `service`**
 * (all one user, authored by `system-tenant-seeding` 02). `Role::from('service')` throws
 * `ValueError`, which is an uncaught fatal — so both membership gates answered a real authorization
 * question with **HTTP 500** rather than 403, on `DELETE /api/v1/beam/accounts/members/…` and
 * `POST /api/v1/beam/accounts/invitations`.
 *
 * Both `TeamContract` readers are covered, because the estate has both shapes:
 * {@see \Splicewire\Beam\Accounts\Concerns\HasMembers::memberRole()} (a host's foreign pivot — the
 * flagship's `tenant_users`, modelled here by {@see HostTeam}) and
 * {@see \Splicewire\Beam\Accounts\Models\Team::memberRole()} (beam's own `memberships`). Both read a
 * value the CALLING author never chose, so both contain rather than throw. The four *caller*-supplied
 * `Role::from()` sites (`TeamProvisioner:62,76`, `TeamMembers:29,57`) deliberately still throw — that
 * is grammar the author could have gotten right.
 *
 * ## Gate posture: CLOSED, and asserted rather than assumed
 *
 * This package installs no `Gate::before` of any kind — `grep -rn 'Gate::before' src/ tests/` matches
 * only prose. That is a claim about a file, though, and a trace taken with the gate open measures
 * nothing, so the first case below PROVES the posture from inside the running container: an ability
 * defined to return `false` must actually deny. A `Gate::before(fn () => true)` anywhere in the boot
 * chain turns that assertion red before it can launder a false result into this file. Every case also
 * asserts the actor is unprivileged (not the team's owner, holding no allow-listed role) and carries a
 * POSITIVE CONTROL — an `owner` seat must still be permitted — so the suite cannot pass by denying
 * everyone, which is the other half of the same instrument failure.
 *
 * ## What this test does NOT claim
 *
 * `tryFrom` is containment, not resolution. These cases pin that a `service` seat denies cleanly; they
 * deliberately also pin the COST — {@see it('records the contract disagreement tryFrom buys')} — so
 * the divergence between `hasMember()` and `memberRole()` is a measured fact with a failing test behind
 * it rather than a comment. See the note at `HasMembers::memberRole()`.
 */
beforeEach(function () {
    Schema::create('host_teams', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('host_team_users', function (Blueprint $table): void {
        $table->id();
        $table->string('host_team_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role')->default('member');
        $table->timestamp('removed_at')->nullable();
        $table->timestamps();
        $table->unique(['host_team_id', 'user_id']);
    });

    // The beam-owned shape: a real personal team over `memberships`, whose owner seat is the
    // positive control for `Team::memberRole()`.
    $this->owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
    $this->beamTeam = $this->owner->personalTeam();

    // The machine identity. Never invited, never assigned a Role — its seat is written straight to
    // the role column the way a seeder writes it, which is exactly how the flagship's 17 rows exist.
    $this->service = User::create([
        'name' => 'Service',
        'email' => 'service@example.test',
        'password' => 'password-1234',
    ]);

    DB::table(Beam::table('memberships'))->insert([
        'team_id' => $this->beamTeam->getKey(),
        'user_id' => $this->service->getKey(),
        'role' => 'service',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The host-pivot shape: `HasMembers` over a foreign pivot with soft removal — the flagship's
    // `tenant_users` without a tenancy dependency.
    $this->hostTeam = HostTeam::create(['id' => 'host-team-1', 'name' => 'Host Team']);

    DB::table('host_team_users')->insert([
        ['host_team_id' => 'host-team-1', 'user_id' => $this->owner->getKey(), 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()],
        ['host_team_id' => 'host-team-1', 'user_id' => $this->service->getKey(), 'role' => 'service', 'created_at' => now(), 'updated_at' => now()],
    ]);
});

it('runs with the gate CLOSED, and proves it from inside the container', function () {
    Gate::define('beam-accounts-gate-posture-control', fn () => false);

    // Under `Gate::before(fn () => true)` — the posture that let a sibling ticket record a forged
    // owner as accepted — this is TRUE and every denial below becomes meaningless.
    expect(Gate::forUser($this->service)->allows('beam-accounts-gate-posture-control'))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('beam-accounts-gate-posture-control'))->toBeFalse();

    // And the actor under test is genuinely unprivileged: not the team's owner on either shape, and
    // holding no role the two allow-lists admit.
    expect($this->beamTeam->user_id)->not->toBe($this->service->getKey())
        ->and(DB::table(Beam::table('memberships'))
            ->where('team_id', $this->beamTeam->getKey())
            ->where('user_id', $this->service->getKey())
            ->value('role'))->toBe('service')
        ->and(array_map(fn (Role $r) => $r->value, Role::cases()))->not->toContain('service');
});

it('denies a service seat on manageMembers instead of fataling — host pivot (HasMembers)', function () {
    expect(Gate::forUser($this->service)->allows('manageMembers', $this->hostTeam))->toBeFalse();
});

it('denies a service seat on manageInvitations instead of fataling — host pivot (HasMembers)', function () {
    expect(Gate::forUser($this->service)->allows('manageInvitations', $this->hostTeam))->toBeFalse();
});

it('denies a service seat on manageMembers instead of fataling — beam memberships (Team)', function () {
    expect(Gate::forUser($this->service)->allows('manageMembers', $this->beamTeam))->toBeFalse();
});

it('denies a service seat on manageInvitations instead of fataling — beam memberships (Team)', function () {
    expect(Gate::forUser($this->service)->allows('manageInvitations', $this->beamTeam))->toBeFalse();
});

/*
 * The 403-vs-500 claim itself. `Gate::authorize()` is what a host controller calls, and the two
 * outcomes are distinguishable at package tier without an HTTP kernel: an `AuthorizationException`
 * carries status 403 and is rendered as such, while a `ValueError` escapes the handler as a 500.
 * Asserting the exception TYPE is therefore the same measurement the flagship took over HTTP.
 */
it('raises a 403-shaped AuthorizationException, never a 500-shaped ValueError', function () {
    foreach (['manageMembers', 'manageInvitations'] as $ability) {
        foreach ([$this->hostTeam, $this->beamTeam] as $team) {
            try {
                Gate::forUser($this->service)->authorize($ability, $team);
                $this->fail("{$ability} permitted a service seat on ".$team::class);
            } catch (AuthorizationException $e) {
                expect($e->status() ?? 403)->toBe(403);
            }
        }
    }
});

it('still permits an owner seat — the positive control', function () {
    expect(Gate::forUser($this->owner)->allows('manageMembers', $this->hostTeam))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('manageInvitations', $this->hostTeam))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('manageMembers', $this->beamTeam))->toBeTrue()
        ->and(Gate::forUser($this->owner)->allows('manageInvitations', $this->beamTeam))->toBeTrue()
        ->and($this->hostTeam->memberRole($this->owner))->toBe(Role::Owner)
        ->and($this->beamTeam->memberRole($this->owner))->toBe(Role::Owner);
});

/*
 * The cost, pinned rather than described. `TeamContract:47`'s docblock says null means "not a
 * member" — after `tryFrom` a `service` seat is `hasMember() === true` AND `memberRole() === null`,
 * so the two contract methods disagree about the same user. Nothing in the estate reads null that
 * way today (both policies are allow-lists that deny on null), which is why the change is safe; an
 * ability later written as `if ($team->memberRole($u) === null) { abort(404); }` would 404 a user who
 * genuinely holds a seat, and the 500 that announced the vocabulary was incomplete is gone. If this
 * case ever needs changing, the real question is the deferred one: is `service` a membership role, or
 * a machine-identity axis wearing the role column?
 */
it('records the contract disagreement tryFrom buys', function () {
    expect($this->hostTeam->hasMember($this->service))->toBeTrue()
        ->and($this->hostTeam->memberRole($this->service))->toBeNull()
        ->and($this->beamTeam->hasMember($this->service))->toBeTrue()
        ->and($this->beamTeam->memberRole($this->service))->toBeNull();
});
