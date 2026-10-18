<?php

// L3 ticket 09 C4 (orch-lead 23:37Z): SetCurrentTeamPermissions binds spatie's team from the guard that actually
// authenticated the request, the account (session) guard first, then the request's own (`auth:sanctum` makes Sanctum the
// default), and binds nothing when neither has a user. A bearer request had no session user, so the team stayed unset
// and every team-scoped role was invisible. A second, non-session guard stands in for Sanctum here: the contract is the
// request's authenticated guard, whichever it is.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Http\Middleware\SetCurrentTeamPermissions;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

beforeEach(function () {
    config()->set('auth.guards.token_probe', ['driver' => 'session', 'provider' => 'users']);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function teamOwner(string $email): array
{
    $user = User::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    $team = Team::create(['user_id' => $user->id, 'name' => $email, 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => Role::Owner->value]);
    $user->switchTeam($team);

    return [$user->fresh(), $team];
}

/** Run the middleware for a request whose authenticating guard is `$guard` (null: nobody authenticated). */
function teamBoundFor(?string $guard): mixed
{
    if ($guard !== null) {
        Auth::shouldUse($guard);
    }
    $request = Request::create('/api/v1/probe');
    $request->setUserResolver(fn (?string $name = null) => Auth::guard($name)->user());

    return (new SetCurrentTeamPermissions)->handle($request, fn () => app(PermissionRegistrar::class)->getPermissionsTeamId());
}

it('binds the team of a user the request authenticated through a non-session guard (a bearer request)', function () {
    [$owner, $team] = teamOwner('bearer@example.test');
    Auth::guard('token_probe')->setUser($owner);            // the session (account) guard has nobody

    expect(teamBoundFor('token_probe'))->toBe($team->getKey());
});

it('prefers the account guard when the session has a user', function () {
    [$sessionUser, $sessionTeam] = teamOwner('session@example.test');
    [$tokenUser] = teamOwner('token@example.test');
    Auth::guard()->setUser($sessionUser);
    Auth::guard('token_probe')->setUser($tokenUser);

    expect(teamBoundFor('token_probe'))->toBe($sessionTeam->getKey());
});

it('binds no team when no guard has a user', function () {
    expect(teamBoundFor(null))->toBeNull();
});

// orch-peer HIGH on f7c720c: the registrar is a singleton, so a request that resolves no team must not keep the last one.
it('clears a stale team when no guard has a user', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId('previous-team');

    expect(teamBoundFor(null))->toBeNull();
});

it('clears a stale team when the user has no team', function () {
    $loner = User::create(['name' => 'Loner', 'email' => 'loner@example.test', 'password' => 'x']);
    Auth::guard('token_probe')->setUser($loner);
    app(PermissionRegistrar::class)->setPermissionsTeamId('previous-team');

    expect($loner->currentTeamOrPersonal())->toBeNull()
        ->and(teamBoundFor('token_probe'))->toBeNull();
});
