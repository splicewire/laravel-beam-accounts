<?php

use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Teams\TeamMembers;
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
        'password_confirmation' => 'password-1234',
    ]);
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
