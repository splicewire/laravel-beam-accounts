<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * credential-scope 07, review cs07 #2: the team roster (`Account\TeamMemberController`) through its real HTTP door, the
 * package's `splicewireAccountApiRoutes()` mounted on `auth:sanctum`, with real Sanctum bearers whose abilities the
 * package's own TokenAbilitiesScopeResolver reads (the binding a host gets with `scope_enforcement` on; bound here because
 * the test enables enforcement after boot). Three cases per door: name missing (coded refusal), name carried, principal
 * refused whatever the token carries. The flagship serves its roster through beam-tenancy's own controller instead.
 */
beforeEach(function () {
    config(['beam.accounts.api.scope_enforcement' => true]);
    // The package's test app loads no Sanctum provider; a host's does (as ApiLogoutTest registers it).
    $this->app->register(Laravel\Sanctum\SanctumServiceProvider::class);
    app()->singleton(CredentialScopeResolver::class, TokenAbilitiesScopeResolver::class);
    $this->createPersonalAccessTokensSchema();
    Route::splicewireAccountApiRoutes(null, ['auth:sanctum']);
    Route::getRoutes()->refreshNameLookups();
});

/** The suite's fixture user, able to hold personal access tokens as a host's user model does. */
class Cs07RosterTokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}

function cs07RosterUser(string $email): Cs07RosterTokenUser
{
    return Cs07RosterTokenUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function cs07RosterBearer($test, Cs07RosterTokenUser $user, array $abilities)
{
    Auth::forgetGuards();

    return $test->withoutToken()->withToken($user->createToken('cs07', $abilities)->plainTextToken);
}

it('narrows the roster reads and the owner writes at their HTTP doors with real bearers', function () {
    $owner = cs07RosterUser('cs07-roster-owner@example.test');
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    $member = cs07RosterUser('cs07-roster-member@example.test');
    Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
    $member->switchTeam($team);
    $outsider = cs07RosterUser('cs07-roster-outsider@example.test');
    $view = PermissionNamer::assemble(Team::class, 'view-members');
    $manage = PermissionNamer::assemble(Team::class, 'manage-members');

    // reads: missing / carried / a principal who is no member of the current team
    cs07RosterBearer($this, $owner->fresh(), ['entity.view'])->getJson('/beam/accounts/members')
        ->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');
    cs07RosterBearer($this, $owner->fresh(), [$view])->getJson('/beam/accounts/members')->assertOk();
    cs07RosterBearer($this, $owner->fresh(), [$view])->getJson('/beam/accounts/members/roles')->assertOk();
    cs07RosterBearer($this, $outsider->fresh(), [$view])->getJson('/beam/accounts/members')
        ->assertForbidden()->assertJsonMissing(['code' => 'credential_scope_name_not_carried']);

    // owner writes: missing / a member (not the owner) carrying it / carried
    cs07RosterBearer($this, $owner->fresh(), [$view])->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'admin'])
        ->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Member);
    cs07RosterBearer($this, $member->fresh(), [$manage])->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'owner'])
        ->assertForbidden()->assertJsonMissing(['code' => 'credential_scope_name_not_carried']);
    cs07RosterBearer($this, $owner->fresh(), [$manage])->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'admin'])
        ->assertOk();
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Admin);
});
