<?php

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Authorization\ModelOrOperatorReadPolicy;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

it('declares model-or-operator read authority for invitations', function () {
    $resource = app(ParticleResourceRegistry::class)->get('invitations');

    expect($resource->readPolicy)->toBe(ModelOrOperatorReadPolicy::class);
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
    $policy = app(ModelOrOperatorReadPolicy::class);

    expect($policy->inspect($member, $resource, $request('tenant'))->allowed())->toBeTrue()
        ->and($policy->inspect($member, $resource, $request('operator'))->allowed())->toBeTrue()
        ->and($policy->inspect($operator, $resource, $request('operator'))->allowed())->toBeTrue()
        ->and($policy->inspect($operator, $resource, $request('tenant'))->denied())->toBeTrue()
        ->and($policy->inspect($stranger, $resource, $request('operator'))->denied())->toBeTrue();
});

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
