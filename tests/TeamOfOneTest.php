<?php

use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Fortify\CreateNewUser;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

it('provisions a team-of-one when creating a user', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    expect($user)->toBeInstanceOf(User::class);

    $team = $user->personalTeam();
    expect($team)->not->toBeNull();
    expect($team->personal_team)->toBeTrue();
    expect($user->current_team_id)->toBe($team->id);

    $membership = $user->memberships()->where('team_id', $team->id)->first();
    expect($membership->role)->toBe(Role::Owner->value);
});

it('assigns the spatie owner role scoped to the personal team', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Grace',
        'email' => 'grace@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($user->personalTeam()->id);
    $user->unsetRelation('roles');

    expect($user->hasRole(Role::Owner->value))->toBeTrue();
});

it('adds a member with a role through the provisioner', function () {
    $owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    $team = $owner->personalTeam();
    $member = User::create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'password-1234']);

    app(TeamProvisioner::class)->addMember($member, $team, Role::Admin->value);

    expect($member->teamRole($team))->toBe(Role::Admin->value);
});

it('rejects a duplicate email at registration', function () {
    User::create(['name' => 'A', 'email' => 'dupe@example.test', 'password' => 'password-1234']);

    app(CreateNewUser::class)->create([
        'name' => 'B',
        'email' => 'dupe@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
})->throws(ValidationException::class);
