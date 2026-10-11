<?php

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Accounts\Authorization\InvitationReadPolicy;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

it('declares the invitation read authority', function () {
    $resource = app(ParticleResourceRegistry::class)->get('invitations');

    expect($resource->readPolicy)->toBe(InvitationReadPolicy::class);
});

it('keeps invitation model readers and operator mounts as separate authority arms', function () {
    $resource = app(ParticleResourceRegistry::class)->get('invitations');
    Gate::policy(Invitation::class, InvitationReadFixturePolicy::class);

    $member = (new InvitationReadActor)->forceFill(['id' => 1]);
    $member->modelReader = true;
    $operator = (new InvitationReadActor)->forceFill(['id' => 2]);
    $operator->operator = true;
    $stranger = (new InvitationReadActor)->forceFill(['id' => 3]);
    Gate::define('entitlement:os.operate', fn (InvitationReadActor $actor): bool => $actor->operator);

    $request = function (string $realm): Request {
        $request = Request::create('/');
        $route = new Route('GET', '/', fn () => null);
        $route->defaults('realm', $realm);
        $request->setRouteResolver(fn () => $route);

        return $request;
    };
    $policy = app(InvitationReadPolicy::class);

    expect($policy->inspect($member, $resource, $request('tenant'))->allowed())->toBeTrue()
        ->and($policy->inspect($member, $resource, $request('operator'))->allowed())->toBeTrue()
        ->and($policy->inspect($operator, $resource, $request('operator'))->allowed())->toBeTrue()
        ->and($policy->inspect($operator, $resource, $request('tenant'))->denied())->toBeTrue()
        ->and($policy->inspect($stranger, $resource, $request('operator'))->denied())->toBeTrue();
});

it('admits real current-team owners and admins without widening the invitation rows', function (Role $role) {
    $manager = User::create([
        'name' => $role->label(),
        'email' => "{$role->value}@example.test",
        'password' => 'x',
    ]);
    $team = Team::create(['user_id' => $manager->id, 'name' => "{$role->label()} Team"]);
    Membership::create(['team_id' => $team->id, 'user_id' => $manager->id, 'role' => $role->value]);
    $manager->switchTeam($team);
    Auth::login($manager = $manager->fresh());

    $other = User::create(['name' => 'Other', 'email' => "other-{$role->value}@example.test", 'password' => 'x']);
    $otherTeam = Team::create(['user_id' => $other->id, 'name' => 'Other Team']);

    Invitation::create([
        'team_id' => $team->id,
        'email' => 'a@example.test',
        'role' => Role::Member->value,
        'token' => str_repeat('a', 64),
        'invited_by' => $manager->id,
    ]);
    Invitation::create([
        'team_id' => $otherTeam->id,
        'email' => 'b@example.test',
        'role' => Role::Member->value,
        'token' => str_repeat('b', 64),
        'invited_by' => $other->id,
    ]);

    Gate::policy(Invitation::class, InvitationReadFixturePolicy::class);
    $resource = app(ParticleResourceRegistry::class)->get('invitations');
    $request = Request::create('/');
    $route = new Route('GET', '/', fn () => null);
    $route->defaults('realm', 'tenant');
    $request->setRouteResolver(fn () => $route);

    expect(app(InvitationReadPolicy::class)->inspect($manager, $resource, $request)->allowed())->toBeTrue()
        ->and(InvitationData::scope(Invitation::query())->pluck('email')->all())->toBe(['a@example.test']);
})->with([Role::Owner, Role::Admin]);

it('keeps a foreign-team owner out of team A rows and denies a plain member of A', function () {
    $ownerA = User::create(['name' => 'Owner A', 'email' => 'owner-a@example.test', 'password' => 'x']);
    $teamA = Team::create(['user_id' => $ownerA->id, 'name' => 'Team A']);
    Membership::create(['team_id' => $teamA->id, 'user_id' => $ownerA->id, 'role' => Role::Owner->value]);
    $memberA = User::create(['name' => 'Member A', 'email' => 'member-a@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $teamA->id, 'user_id' => $memberA->id, 'role' => Role::Member->value]);
    $memberA->switchTeam($teamA);

    $ownerB = User::create(['name' => 'Owner B', 'email' => 'owner-b@example.test', 'password' => 'x']);
    $teamB = Team::create(['user_id' => $ownerB->id, 'name' => 'Team B']);
    Membership::create(['team_id' => $teamB->id, 'user_id' => $ownerB->id, 'role' => Role::Owner->value]);
    $ownerB->switchTeam($teamB);

    Invitation::create([
        'team_id' => $teamA->id,
        'email' => 'a@example.test',
        'role' => Role::Member->value,
        'token' => str_repeat('a', 64),
        'invited_by' => $ownerA->id,
    ]);
    Invitation::create([
        'team_id' => $teamB->id,
        'email' => 'b@example.test',
        'role' => Role::Member->value,
        'token' => str_repeat('b', 64),
        'invited_by' => $ownerB->id,
    ]);

    Gate::policy(Invitation::class, InvitationReadFixturePolicy::class);
    $resource = app(ParticleResourceRegistry::class)->get('invitations');
    $request = Request::create('/');
    $route = new Route('GET', '/', fn () => null);
    $route->defaults('realm', 'tenant');
    $request->setRouteResolver(fn () => $route);
    $policy = app(InvitationReadPolicy::class);

    Auth::login($ownerB = $ownerB->fresh());
    expect($policy->inspect($ownerB, $resource, $request)->allowed())->toBeTrue()
        ->and(InvitationData::scope(Invitation::query())->pluck('email')->all())->toBe(['b@example.test']);

    Auth::login($memberA = $memberA->fresh());
    expect($policy->inspect($memberA, $resource, $request)->denied())->toBeTrue();
});

class InvitationReadFixturePolicy
{
    public function viewAny(AuthUser $actor): bool
    {
        return $actor instanceof InvitationReadActor && $actor->modelReader;
    }
}

class InvitationReadActor extends AuthUser
{
    public bool $modelReader = false;

    public bool $operator = false;
}
