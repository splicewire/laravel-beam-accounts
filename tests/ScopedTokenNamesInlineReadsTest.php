<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Authorization\CredentialScopeRefused;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\QueryBuilders\UsersQuery;
use Splicewire\Beam\Accounts\Teams\TeamMembers;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * Fleet ADR 0006 C2/C3 at beam-accounts' checks made OUTSIDE the Gate (credential-scope 07 R2): the team roster's
 * membership and owner reads, the `TeamMembers` lifecycle's owner check and `UsersQuery`'s Root arm each ask a scoped
 * token for their decision's name; the principal half is unchanged, and a session or a `*` token (a null scope) is
 * unchanged. Their class-level names are offered to the widest token set.
 */
beforeEach(function () {
    config(['beam.accounts.api.scope_enforcement' => true]);
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();
});

/** @param  list<string>|null  $scope  null: unscoped (a session, a `*` token) */
function cs07Scope(?array $scope): void
{
    app()->instance(CredentialScopeResolver::class, new class($scope) implements CredentialScopeResolver
    {
        public function __construct(private ?array $scope) {}

        public function resolve(): ?array
        {
            return $this->scope;
        }
    });
}

/** @return array{0: User, 1: Team} */
function cs07Owner(): array
{
    $owner = User::create(['name' => 'Owner', 'email' => 'cs07-owner@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());

    return [$owner->fresh(), $team];
}

function cs07Seat(Team $team, string $email, Role $role): User
{
    $user = User::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => $role->value]);
    $user->switchTeam($team);

    return $user->fresh();
}

it('asks a scoped token for viewMembers on the team before listing the roster or its roles', function () {
    [, $team] = cs07Owner();
    cs07Seat($team, 'cs07-member@example.test', Role::Member);
    $view = PermissionNamer::assemble($team, 'view-members');

    cs07Scope(['entity.view']);
    $this->getJson('/beam/accounts/members')->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');
    $this->getJson('/beam/accounts/members/roles')->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');

    cs07Scope([PermissionNamer::assemble(Team::class, 'view-members')]);
    $this->getJson('/beam/accounts/members')->assertOk();
    cs07Scope([$view]);
    $this->getJson('/beam/accounts/members/roles')->assertOk();

    cs07Scope(null);
    $this->getJson('/beam/accounts/members')->assertOk();
});

it('asks a scoped owner token for manageMembers before changing a role or removing a member, and keeps the owner check', function () {
    [, $team] = cs07Owner();
    $member = cs07Seat($team, 'cs07-member@example.test', Role::Member);

    cs07Scope([PermissionNamer::assemble(Team::class, 'view-members')]);
    $this->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'admin'])->assertForbidden()
        ->assertJsonPath('code', 'credential_scope_name_not_carried');
    $this->deleteJson("/beam/accounts/members/{$member->id}")->assertForbidden()
        ->assertJsonPath('code', 'credential_scope_name_not_carried');
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Member);

    cs07Scope([PermissionNamer::assemble(Team::class, 'manage-members')]);
    $this->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'admin'])->assertOk();
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Admin);

    // the principal half: a member carrying the name is still not the owner
    Auth::login($member->fresh());
    $this->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'owner'])->assertForbidden()
        ->assertJsonMissing(['code' => 'credential_scope_name_not_carried']);
});

it('asks a scoped token for manageMembers in the TeamMembers lifecycle', function () {
    [$owner, $team] = cs07Owner();
    $member = cs07Seat($team, 'cs07-member@example.test', Role::Member);

    cs07Scope(['entity.view']);
    expect(fn () => app(TeamMembers::class)->changeRole($team, $owner, $member, Role::Admin))->toThrow(CredentialScopeRefused::class);
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Member);

    cs07Scope([PermissionNamer::assemble($team, 'manage-members')]);
    app(TeamMembers::class)->changeRole($team, $owner, $member, Role::Admin);
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Admin);
});

it('narrows UsersQuery\'s Root arm to user.view under a scope, falling back to the shared-teams scope', function () {
    [, $team] = cs07Owner();
    $root = User::create(['name' => 'Root', 'email' => 'cs07-root@example.test', 'password' => 'x']);
    $root->assignRole(app(config('permission.models.role'))::firstOrCreate(['name' => 'Root', 'guard_name' => 'web', 'team_id' => null]));
    $root = $root->fresh();
    Auth::login($root);
    $everyone = User::query()->count();

    cs07Scope(null);
    expect(UsersQuery::scopeToSharedTeams(User::query(), $root)->count())->toBe($everyone);

    cs07Scope(['entity.view']);
    expect(UsersQuery::scopeToSharedTeams(User::query(), $root)->pluck('id')->all())->toBe([$root->id]);

    cs07Scope([PermissionNamer::assemble(User::class, 'view')]);
    expect(UsersQuery::scopeToSharedTeams(User::query(), $root)->count())->toBe($everyone);
});

it('offers the passkey and team-roster names to the widest token set', function () {
    expect(app(CredentialScopeGate::class)->offerableNames())
        ->toContain('passkey.view', 'passkey.create', 'passkey.update', 'passkey.delete',
            PermissionNamer::assemble(Team::class, 'view-members'), PermissionNamer::assemble(Team::class, 'manage-members'));
});
