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

class InvitationReadFixturePolicy
{
    public function viewAny(InvitationReadActor $actor): bool
    {
        return $actor->modelReader;
    }
}

class InvitationReadActor extends AuthUser
{
    public bool $modelReader = false;

    public bool $operator = false;
}
