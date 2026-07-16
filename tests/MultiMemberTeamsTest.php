<?php

use Illuminate\Auth\Access\AuthorizationException;
use Schemastud\Beam\Accounts\Fortify\CreateNewUser;
use Schemastud\Beam\Accounts\Models\Invitation;
use Schemastud\Beam\Accounts\Support\Roles;
use Schemastud\Beam\Accounts\Teams\TeamMembers;
use Schemastud\Beam\Accounts\Tests\Fixtures\User;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
    $this->team = $this->owner->personalTeam();
    $this->members = app(TeamMembers::class);

    $this->member = User::create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'password-1234']);
    $this->outsider = User::create(['name' => 'Outsider', 'email' => 'out@example.test', 'password' => 'password-1234']);
});

it('runs the full invite -> accept -> change-role -> remove lifecycle', function () {
    // Invite by email + role.
    $invitation = $this->members->invite($this->team, $this->owner, 'member@example.test', Roles::MEMBER);
    expect($invitation->role)->toBe(Roles::MEMBER);
    expect($invitation->token)->not->toBeEmpty();

    // Accept: invitation becomes a membership, invitation consumed.
    $membership = $this->members->accept($invitation, $this->member);
    expect($membership->role)->toBe(Roles::MEMBER);
    expect($this->member->belongsToTeam($this->team))->toBeTrue();
    expect(Invitation::find($invitation->id))->toBeNull();

    // The accepted member carries the spatie member role scoped to the team.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->team->id);
    $this->member->unsetRelation('roles');
    expect($this->member->hasRole(Roles::MEMBER))->toBeTrue();

    // Change role to admin.
    $this->members->changeRole($this->team, $this->owner, $this->member, Roles::ADMIN);
    expect($this->member->fresh()->teamRole($this->team))->toBe(Roles::ADMIN);

    // Remove the member.
    $this->members->remove($this->team, $this->owner, $this->member);
    expect($this->member->fresh()->belongsToTeam($this->team))->toBeFalse();
});

it('lists members of a team', function () {
    $invitation = $this->members->invite($this->team, $this->owner, 'member@example.test', Roles::MEMBER);
    $this->members->accept($invitation, $this->member);

    $roster = $this->members->members($this->team);
    expect($roster)->toHaveCount(2); // owner + accepted member
});

it('forbids a non-owner from inviting', function () {
    $this->members->invite($this->team, $this->outsider, 'x@example.test', Roles::MEMBER);
})->throws(AuthorizationException::class);

it('forbids a non-owner from changing roles', function () {
    $invitation = $this->members->invite($this->team, $this->owner, 'member@example.test', Roles::MEMBER);
    $this->members->accept($invitation, $this->member);

    $this->members->changeRole($this->team, $this->outsider, $this->member, Roles::ADMIN);
})->throws(AuthorizationException::class);

it('refuses to remove the team owner', function () {
    $this->members->remove($this->team, $this->owner, $this->owner);
})->throws(AuthorizationException::class);
