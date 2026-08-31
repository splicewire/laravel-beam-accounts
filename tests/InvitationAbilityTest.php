<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * `manageInvitation` — `manageInvitations` re-subjected onto one {@see Invitation}, which is what a
 * particle operation on an invitation can actually declare.
 *
 * It replaces `splicewire/laravel-beam-tenancy`'s `manageTenantInvitations`, an ability that existed
 * only because it had to reach the invitation's team through a `TenantInvitation::tenant()`
 * relation. With one packaged model whose `team_id` is a `TeamContract` key, the check has no
 * relation to reach through and belongs beside the ability it delegates to.
 *
 * ## Gate posture
 *
 * ⚠️ **The gate is CLOSED for every case here.** Nothing in this file installs
 * `Gate::before(fn () => true)` or any other blanket allow, and the assertions below include denials
 * that only mean something under a closed gate — a peer, an outsider, and a foreign-team invitation
 * all have to be refused. The estate's measured failure mode is an authorization trace taken with
 * the gate open, which reports success by not running; the negative cases are the witness that this
 * one ran.
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
        $table->uuid('user_id');
        $table->string('role')->default('member');
        $table->timestamp('removed_at')->nullable();
        $table->timestamps();
        $table->unique(['host_team_id', 'user_id']);
    });

    $this->team = HostTeam::create(['id' => 'beam_demo', 'name' => 'Demo']);

    config(['beam.accounts.teams.resolver' => fn (): ?object => HostTeam::find('beam_demo')]);

    $this->seat = function (string $email, Role $role): User {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'x']);
        $this->team->assignMember($user, $role);

        return $user;
    };

    $this->invitation = Invitation::create([
        'team_id' => 'beam_demo',
        'email' => 'invitee@example.test',
        'role' => 'member',
        'token' => 'tok',
    ]);
});

afterEach(function () {
    config(['beam.accounts.teams.resolver' => null]);
});

it('admits an owner and an admin, the graduated tier the team ability already had', function () {
    foreach ([Role::Owner, Role::Admin] as $role) {
        $user = ($this->seat)(strtolower($role->value).'@example.test', $role);

        expect(Gate::forUser($user)->allows('manageInvitation', $this->invitation))->toBeTrue();
    }
});

it('refuses a plain member, and refuses a user with no seat at all', function () {
    $member = ($this->seat)('member@example.test', Role::Member);
    $outsider = User::create(['name' => 'Zed', 'email' => 'zed@example.test', 'password' => 'x']);

    expect(Gate::forUser($member)->allows('manageInvitation', $this->invitation))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('manageInvitation', $this->invitation))->toBeFalse();
});

it('refuses an invitation belonging to a DIFFERENT team than the resolved one', function () {
    $owner = ($this->seat)('owner-x@example.test', Role::Owner);

    $foreign = Invitation::create([
        'team_id' => 'some-other-team',
        'email' => 'elsewhere@example.test',
        'role' => 'member',
        'token' => 'tok-foreign',
    ]);

    // The tightening over `manageTenantInvitations`, asserted rather than described: that ability
    // asked about the invitation's OWN team, so it answered TRUE here. This one requires the
    // invitation to belong to the team the host resolved, so it does not depend on the resource
    // scope having been applied first.
    expect(Gate::forUser($owner)->allows('manageInvitation', $foreign))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('manageInvitation', $this->invitation))->toBeTrue();
});

it('refuses when no team resolves, rather than trusting the invitation to name its own authorizer', function () {
    $owner = ($this->seat)('owner-null@example.test', Role::Owner);

    config(['beam.accounts.teams.resolver' => fn (): ?object => null]);

    expect(Gate::forUser($owner)->allows('manageInvitation', $this->invitation))->toBeFalse();
});

it('compares the team key as a string, so a numeric team key is not a type mismatch', function () {
    // `teamKey()` is `int|string` and `Invitation` casts `team_id` to string, so the ability is the
    // one place both sides are normalized. A `===` on the raw values would deny every bigint-keyed
    // host — silently, and as a 403 rather than an error.
    $numeric = HostTeam::create(['id' => '7', 'name' => 'Numeric']);
    config(['beam.accounts.teams.resolver' => fn (): ?object => HostTeam::find('7')]);

    $owner = User::create(['name' => 'Own', 'email' => 'own-num@example.test', 'password' => 'x']);
    $numeric->assignMember($owner, Role::Owner);

    $invitation = Invitation::create([
        'team_id' => 7,
        'email' => 'num@example.test',
        'role' => 'member',
        'token' => 'tok-num',
    ]);

    expect(Gate::forUser($owner)->allows('manageInvitation', $invitation))->toBeTrue();
});

it('lives on the app default connection until a host names one', function () {
    // The seam that lets this one model serve a tenanted host. Unset must mean the app default —
    // the majority of this package's hosts are single-database and have no `central` to name.
    expect((new Invitation)->getConnectionName())->toBeNull();

    config(['beam.accounts.invitations.connection' => 'central']);

    expect((new Invitation)->getConnectionName())->toBe('central');

    config(['beam.accounts.invitations.connection' => null]);
});
