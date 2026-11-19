<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Tests\Fixtures\HostTeam;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * standwell gap-assessment-build 39 (review sw39-app, blocking): two grants beside the permission cascade let a scoped
 * token through.
 *
 *  - `UserPolicy`'s central Root arms (viewAny/create/update/delete/loginAs): a Root token scoped to `entity.view` could
 *    edit or log in as another user. They now keep a verb only when the token carries its derived name (`user.update`).
 *  - `InvitationPolicy`'s team-role fallback (create/delete): the cascade refused an owner token scoped to
 *    `entity.view`, and the fallback readmitted it. It now asks the same `invitation.*` vocabulary of the token.
 *
 * The scope is the cascade's `CredentialScopeResolver` (what beam-accounts' bearer resolver feeds in a host), bound to
 * a fixed list; the actor is the authenticated request user, which is who beam's CredentialScopeGate narrows. A null
 * scope is a session or a `*` token. The gate is closed throughout (no blanket allow): every denial is a real one.
 */
beforeEach(function () {
    Schema::create('host_teams', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('host_team_users', function (Blueprint $table): void {
        $table->id();
        $table->string('host_team_id');
        $table->uuid('user_id');
        $table->string('role')->default('member');
        $table->timestamp('removed_at')->nullable();
        $table->timestamps();
        $table->unique(['host_team_id', 'user_id']);
    });

    $this->team = HostTeam::create(['id' => 'cb39_team', 'name' => 'CB39']);
    config(['beam.accounts.teams.resolver' => fn (): ?object => HostTeam::find('cb39_team')]);
});

afterEach(function () {
    config(['beam.accounts.teams.resolver' => null]);
});

/** @param  list<string>|null  $scope  null: unscoped (a session, a `*` token) */
function cb39Scope(?array $scope): void
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

function cb39Root(): User
{
    $root = User::create(['name' => 'Root', 'email' => 'cb39-root@example.test', 'password' => 'password-1234']);
    $root->assignRole(app(config('permission.models.role'))::firstOrCreate(['name' => 'Root', 'guard_name' => 'web', 'team_id' => null]));

    return $root->fresh();
}

it('narrows the central Root arms and the self arm of UserPolicy to what the token carries; a session is unchanged', function () {
    $root = cb39Root();
    $other = User::create(['name' => 'Other', 'email' => 'cb39-other@example.test', 'password' => 'password-1234']);
    test()->actingAs($root);
    $update = PermissionNamer::assemble(get_class($other), 'update');
    $loginAs = PermissionNamer::assemble(get_class($other), 'loginAs');

    cb39Scope(null);
    expect(Gate::allows('update', $other))->toBeTrue('unscoped Root edits another user')
        ->and(Gate::allows('loginAs', $other))->toBeTrue('unscoped Root may log in as another user');

    cb39Scope(['entity.view']);
    expect(Gate::allows('update', $other))->toBeFalse('a Root token scoped to entity.view may not edit another user')
        ->and(Gate::allows('delete', $other))->toBeFalse()
        ->and(Gate::allows('loginAs', $other))->toBeFalse('nor log in as them')
        ->and(Gate::allows('update', $root))->toBeFalse('the self arm is narrowed too: it is a decision with a name (fleet ADR 0006 C2)');

    cb39Scope([PermissionNamer::assemble($root, 'update')]);
    expect(Gate::allows('update', $root))->toBeTrue('the token carries the name of updating this very user')
        ->and(Gate::allows('update', $other))->toBeFalse();

    cb39Scope([$update, $loginAs]);
    expect(Gate::allows('update', $other))->toBeTrue('the token carries user.update')
        ->and(Gate::allows('loginAs', $other))->toBeTrue()
        ->and(Gate::allows('delete', $other))->toBeFalse('but not user.delete');

    cb39Scope([PermissionNamer::assemble($other, 'delete')]);
    expect(Gate::allows('delete', $other))->toBeTrue('the per-record name admits that record');
});

it('narrows the InvitationPolicy team-role fallback to the invitation vocabulary the token carries', function () {
    $owner = User::create(['name' => 'Owner', 'email' => 'cb39-owner@example.test', 'password' => 'password-1234']);
    $this->team->assignMember($owner, Role::Owner);
    $invitation = Invitation::create(['team_id' => 'cb39_team', 'email' => 'invitee@example.test', 'role' => 'member', 'token' => 'cb39-tok']);
    test()->actingAs($owner);

    // The host seeded its own roles, so the owner holds no invitation.* token: the team-role arm is what admits them.
    cb39Scope(null);
    expect(Gate::allows('create', Invitation::class))->toBeTrue('unscoped owner invites through the team-role arm')
        ->and(Gate::allows('delete', $invitation))->toBeTrue('and revokes');

    cb39Scope(['entity.view']);
    expect(Gate::allows('create', Invitation::class))->toBeFalse('a token lacking invitation.create is refused')
        ->and(Gate::allows('delete', $invitation))->toBeFalse('a token lacking invitation.delete is refused')
        ->and(Gate::allows('delete', new Invitation))->toBeFalse('the class-level revoke probe too');

    cb39Scope([PermissionNamer::assemble(Invitation::class, 'create'), PermissionNamer::assemble(Invitation::class, 'delete')]);
    expect(Gate::allows('create', Invitation::class))->toBeTrue()
        ->and(Gate::allows('delete', $invitation))->toBeTrue();

    cb39Scope([PermissionNamer::assemble($invitation, 'delete')]);
    expect(Gate::allows('delete', $invitation))->toBeTrue('the per-record name admits that invitation')
        ->and(Gate::allows('create', Invitation::class))->toBeFalse();
});

it('still refuses a plain member under any scope, so the scope only subtracts', function () {
    $member = User::create(['name' => 'Mem', 'email' => 'cb39-member@example.test', 'password' => 'password-1234']);
    $this->team->assignMember($member, Role::Member);
    test()->actingAs($member);

    cb39Scope([PermissionNamer::assemble(Invitation::class, 'create')]);
    expect(Gate::allows('create', Invitation::class))->toBeFalse();
});
