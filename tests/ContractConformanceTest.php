<?php

use Schemastud\Beam\Accounts\Contracts\MembershipContract;
use Schemastud\Beam\Accounts\Contracts\TeamContract;
use Schemastud\Beam\Accounts\Enums\Role;
use Schemastud\Beam\Accounts\Fortify\CreateNewUser;
use Schemastud\Beam\Accounts\Models\Membership;
use Schemastud\Beam\Accounts\Models\Team;
use Schemastud\Beam\Accounts\Tests\Fixtures\User;

beforeEach(function () {
    $this->owner = app(CreateNewUser::class)->create([
        'name' => 'Owner',
        'email' => 'owner@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);
    $this->team = $this->owner->personalTeam();
    $this->member = User::create(['name' => 'Member', 'email' => 'member@example.test', 'password' => 'password-1234']);
});

it('makes the concrete Team the reference implementation of TeamContract', function () {
    expect($this->team)->toBeInstanceOf(TeamContract::class);
});

it('makes the concrete Membership implement MembershipContract', function () {
    $membership = Membership::where('team_id', $this->team->getKey())->firstOrFail();

    expect($membership)->toBeInstanceOf(MembershipContract::class)
        ->and($membership->memberUser()->getKey())->toBe($this->owner->getKey())
        ->and($membership->memberRole())->toBe(Role::Owner)
        ->and($membership->isActive())->toBeTrue();
});

it('exposes the team surface through the contract methods', function () {
    /** @var TeamContract $team */
    $team = $this->team;

    expect($team->teamKey())->toBe($this->team->getKey())
        ->and($team->hasMember($this->owner))->toBeTrue()
        ->and($team->hasMember($this->member))->toBeFalse()
        ->and($team->memberRole($this->owner))->toBe(Role::Owner)
        ->and($team->memberRole($this->member))->toBeNull();

    // assignMember is idempotent and updates an existing seat's role.
    $team->assignMember($this->member, Role::Member);
    expect($team->hasMember($this->member))->toBeTrue()
        ->and($team->memberRole($this->member))->toBe(Role::Member);

    $team->assignMember($this->member, Role::Admin);
    expect($team->memberRole($this->member))->toBe(Role::Admin)
        ->and(Membership::where('team_id', $team->teamKey())->where('user_id', $this->member->getKey())->count())->toBe(1);

    // removeMember detaches the seat.
    $team->removeMember($this->member);
    expect($team->hasMember($this->member))->toBeFalse();
});

it('reads a team\'s members and invitations through the contract', function () {
    /** @var Team&TeamContract $team */
    $team = $this->team;

    expect($team->members()->get())->toHaveCount(1); // owner
    expect($team->invitations()->count())->toBe(0);
});
