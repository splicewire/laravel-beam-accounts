<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Teams\TeamMembers;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * The two graduated tiers of the membership axis. `manageMembers` (change role / remove / ownership
 * transfer) is owner-only; `manageInvitations` (send / resend / revoke) admits owner AND admin. This
 * split is what lets a host (the Splicewire app's Team surface) authorize invites off one policy seam
 * instead of a hand-rolled `in_array([Owner, Admin])` check — admin-redesign ticket 05.
 */

beforeEach(function () {
    $this->policy = new MembershipPolicy;

    $this->owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => 'password-1234',
        'passwordConfirmation' => 'password-1234',
    ]);
    // A team-of-one, provisioned explicitly: registration provisions no team (purchase-walkthrough M10).
    app(TeamProvisioner::class)->personalTeamFor($this->owner);
    $this->owner = $this->owner->fresh();
    $this->team = $this->owner->personalTeam();
    $members = app(TeamMembers::class);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-1234']);
    $members->accept($members->invite($this->team, $this->owner, 'admin@example.test', Role::Member->value), $this->admin);
    $members->changeRole($this->team, $this->owner, $this->admin, Role::Admin->value);

    $this->member = User::create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'password-1234']);
    $members->accept($members->invite($this->team, $this->owner, 'member@example.test', Role::Member->value), $this->member);
});

it('gates manageMembers to the owner only', function () {
    expect($this->policy->manageMembers($this->owner, $this->team))->toBeTrue()
        ->and($this->policy->manageMembers($this->admin->fresh(), $this->team))->toBeFalse()
        ->and($this->policy->manageMembers($this->member->fresh(), $this->team))->toBeFalse()
        ->and($this->policy->manageMembers(null, $this->team))->toBeFalse();
});

it('admits owner AND admin to manageInvitations, but not a plain member', function () {
    expect($this->policy->manageInvitations($this->owner, $this->team))->toBeTrue()
        ->and($this->policy->manageInvitations($this->admin->fresh(), $this->team))->toBeTrue()
        ->and($this->policy->manageInvitations($this->member->fresh(), $this->team))->toBeFalse()
        ->and($this->policy->manageInvitations(null, $this->team))->toBeFalse();
});

it('denies membership abilities for a host member whose seat was removed', function () {
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

    $team = HostTeam::create(['id' => 'host-team-policy', 'name' => 'Host team']);
    $formerOwner = User::create([
        'name' => 'Former owner',
        'email' => 'former-owner@example.test',
        'password' => 'password-1234',
    ]);
    $team->assignMember($formerOwner, Role::Owner);

    expect($this->policy->manageMembers($formerOwner, $team))->toBeTrue()
        ->and($this->policy->manageInvitations($formerOwner, $team))->toBeTrue();

    $team->removeMember($formerOwner);

    expect($team->hasMember($formerOwner))->toBeFalse()
        ->and($team->memberRole($formerOwner))->toBeNull()
        ->and($this->policy->manageMembers($formerOwner, $team))->toBeFalse()
        ->and($this->policy->manageInvitations($formerOwner, $team))->toBeFalse();
});
