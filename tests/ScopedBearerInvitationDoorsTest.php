<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Facades\PermissionNamer;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * credential-scope 08 (07's R6 route-first sweep, the tower starter's rows): the team invitations
 * (`Account\TeamInvitationController`: list, send, resend, revoke) ran no decision for a scoped token, so a token scoped
 * to anything listed, sent, re-sent and revoked the current team's invitations. Each now asks the token: the list
 * `invitation.view`, the writes `{team}.manage-invitations` (the `manageInvitations` Gate ability's derived names). The
 * owner/admin check stays the principal half.
 *
 * Real Sanctum bearers through the package's `splicewireAccountApiRoutes()` on `auth:sanctum` (the tower starter's
 * mount), with the package's TokenAbilitiesScopeResolver bound (the binding a host gets with `scope_enforcement` on).
 */
beforeEach(function () {
    config(['beam.accounts.api.scope_enforcement' => true]);
    $this->app->register(Laravel\Sanctum\SanctumServiceProvider::class);
    app()->singleton(CredentialScopeResolver::class, TokenAbilitiesScopeResolver::class);
    $this->createPersonalAccessTokensSchema();
    Route::splicewireAccountApiRoutes(null, ['auth:sanctum']);
    Route::getRoutes()->refreshNameLookups();
    Mail::fake();
    Notification::fake();
});

class Cs08bInvitationTokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}

function cs08bInvUser(string $email): Cs08bInvitationTokenUser
{
    return Cs08bInvitationTokenUser::create(['name' => $email, 'email' => $email, 'password' => 'x']);
}

function cs08bInvBearer($test, Cs08bInvitationTokenUser $user, array $abilities)
{
    Auth::forgetGuards();

    return $test->withoutToken()->withToken($user->createToken('cs08b', $abilities)->plainTextToken);
}

/** @return array{0: Cs08bInvitationTokenUser, 1: Team, 2: Invitation, 3: Cs08bInvitationTokenUser} owner, team, a pending invitation, a member */
function cs08bInvTeam(): array
{
    $owner = cs08bInvUser('cs08b-inv-owner@example.test');
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    $member = cs08bInvUser('cs08b-inv-member@example.test');
    Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
    $member->switchTeam($team);
    $pending = Invitation::create(['team_id' => (string) $team->id, 'email' => 'pending@example.test', 'role' => 'member', 'token' => 'cs08b-token', 'invited_by' => $owner->id]);

    return [$owner->fresh(), $team, $pending, $member->fresh()];
}

function cs08bInvState(): array
{
    return Invitation::query()->orderBy('id')->get(['id', 'email', 'role', 'token', 'updated_at'])->map->toArray()->all();
}

it('refuses every invitation verb to a scoped bearer lacking its name, writing and mailing nothing', function () {
    [$owner, $team, $pending] = cs08bInvTeam();
    $manage = PermissionNamer::assemble(Team::class, 'manage-invitations');
    $before = cs08bInvState();

    cs08bInvBearer($this, $owner, [$manage, 'entity.view'])->getJson('/beam/accounts/invitations')
        ->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');
    foreach ([
        ['postJson', '/beam/accounts/invitations', ['email' => 'new@example.test', 'role' => 'member']],
        ['postJson', "/beam/accounts/invitations/{$pending->id}/resend", []],
        ['deleteJson', "/beam/accounts/invitations/{$pending->id}", []],
    ] as [$verb, $path, $body]) {
        cs08bInvBearer($this, $owner, ['invitation.view'])->{$verb}($path, $body)
            ->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');
    }

    expect(cs08bInvState())->toEqual($before);
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

it('admits each invitation verb to a bearer carrying its name, within the owner/admin principal half', function () {
    [$owner, $team, $pending, $member] = cs08bInvTeam();
    $manage = PermissionNamer::assemble(Team::class, 'manage-invitations');

    cs08bInvBearer($this, $owner, ['invitation.view'])->getJson('/beam/accounts/invitations')->assertOk()->assertJsonCount(1, 'data');
    cs08bInvBearer($this, $owner, [$manage])->postJson('/beam/accounts/invitations', ['email' => 'new@example.test', 'role' => 'member'])->assertCreated();
    cs08bInvBearer($this, $owner, [PermissionNamer::assemble($team, 'manage-invitations')])->postJson("/beam/accounts/invitations/{$pending->id}/resend")->assertOk();
    cs08bInvBearer($this, $owner, [$manage])->deleteJson("/beam/accounts/invitations/{$pending->id}")->assertOk();
    expect(Invitation::query()->whereKey($pending->id)->exists())->toBeFalse();

    // the principal half: a plain member carrying the name is still refused, uncoded, and nothing is written
    $before = cs08bInvState();
    cs08bInvBearer($this, $member, [$manage])->postJson('/beam/accounts/invitations', ['email' => 'm@example.test', 'role' => 'member'])
        ->assertForbidden()->assertJsonMissing(['code' => 'credential_scope_name_not_carried']);
    expect(cs08bInvState())->toEqual($before);

    // a * token is unchanged
    cs08bInvBearer($this, $owner, ['*'])->getJson('/beam/accounts/invitations')->assertOk();
});

it('offers the invitation names to the widest token set', function () {
    expect(app(CredentialScopeGate::class)->offerableNames())
        ->toContain('invitation.view')
        ->toContain(PermissionNamer::assemble(Team::class, 'manage-invitations'));
});
