<?php

use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\Contracts\UnionQuery;

use function Splicewire\Beam\Accounts\accountCurrentTeam;
use function Splicewire\Beam\Accounts\accountTokenModel;

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\Frame\CreateInvitationData;
use Splicewire\Beam\Accounts\Data\Frame\InvitationResourceData;
use Splicewire\Beam\Accounts\Data\Frame\MembershipResourceData;
use Splicewire\Beam\Accounts\Data\Frame\TeamResourceData;
use Splicewire\Beam\Accounts\Data\Frame\TokenResourceData;
use Splicewire\Beam\Accounts\Data\ProfileData;
use Splicewire\Beam\Accounts\Data\ProfileUpdateInputData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\ProfileController;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\QueryBuilders\TokensQuery;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Frame OS ticket 20 — the promoted account + team-admin resources (Tokens / Invitations / Members)
 * exercised at the domain-logic level: the load-bearing scope/prepare/project seams, not the full
 * Frame HTTP transport (beam-core's generic handler, tested there).
 */
beforeEach(function () {
    // The invitation-lifecycle columns the promoted resource reads (see the
    // add_lifecycle_to_invitations_table migration); the base harness builds the pre-lifecycle table.
    Schema::table(Beam::table('invitations'), function (Blueprint $table): void {
        $table->unsignedBigInteger('invited_by')->nullable();
        $table->timestamp('accepted_at')->nullable();
    });

    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->id();
        $table->string('tokenable_type');
        $table->unsignedBigInteger('tokenable_id');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->string('provenance')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->timestamps();
        $table->index(['tokenable_type', 'tokenable_id']);
    });
});

/** Mint a PAT owned by a user (tokenable_* are Sanctum-guarded, so set them raw). */
function mintToken(User $user, string $name, string $seed): PersonalAccessToken
{
    $token = new PersonalAccessToken(['name' => $name, 'token' => hash('sha256', $seed)]);
    $token->tokenable_type = $user->getMorphClass();
    $token->tokenable_id = $user->id;
    $token->save();

    return $token;
}

/** Provision a team-owner user + their personal team. */
function ownerWithTeam(string $email = 'owner@example.test'): array
{
    $owner = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());

    return [$owner->fresh(), $team];
}

// ── Tokens ──────────────────────────────────────────────────────────────────────────────────

it('defaults the token model to the package PAT model', function () {
    expect(accountTokenModel())->toBe(PersonalAccessToken::class);
});

it('scopes the token list to the acting user — never leaks another user\'s tokens', function () {
    [$owner] = ownerWithTeam();
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

    mintToken($owner, 'mine', 'a');
    mintToken($other, 'theirs', 'b');

    $rows = TokenResourceData::scope(PersonalAccessToken::query())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->name)->toBe('mine');
});

it('token scope fails safe (empty) for a guest', function () {
    Auth::logout();
    expect(TokensQuery::scopeToOwner(PersonalAccessToken::query(), null)->count())->toBe(0);
});

it('honours a host token-scope seam over the default', function () {
    [$owner] = ownerWithTeam();
    config(['beam.accounts.tokens.scope' => fn ($q, $user) => $q->whereRaw('1 = 0')]);

    mintToken($owner, 'mine', 'c');

    expect(TokenResourceData::scope(PersonalAccessToken::query())->count())->toBe(0);
});

it('projects a token into the frame row shape', function () {
    [$owner] = ownerWithTeam();
    $token = mintToken($owner, 'ci', 'd');

    $row = TokenResourceData::project($token);

    expect($row->id)->toBe((string) $token->id)
        ->and($row->name)->toBe('ci')
        ->and($row->lastUsedAt)->toBeNull();
});

// ── Invitations ─────────────────────────────────────────────────────────────────────────────

it('prepares a new invitation for the current team as an owner', function () {
    [$owner, $team] = ownerWithTeam();
    $invitation = new Invitation;

    InvitationResourceData::prepare($invitation, CreateInvitationData::from(['email' => 'new@example.test', 'role' => 'member']), $owner);

    expect($invitation->team_id)->toBe($team->id)
        ->and($invitation->email)->toBe('new@example.test')
        ->and($invitation->token)->not->toBeEmpty()
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->invited_by)->toBe($owner->id);
});

it('re-invite updates the existing row (unique team_id,email) instead of colliding', function () {
    [$owner, $team] = ownerWithTeam();
    $first = Invitation::create(['team_id' => $team->id, 'email' => 'dup@example.test', 'role' => 'member', 'token' => 'old']);

    $model = new Invitation;
    InvitationResourceData::prepare($model, CreateInvitationData::from(['email' => 'dup@example.test', 'role' => 'admin']), $owner);
    $model->role = 'admin';
    $model->save();

    expect(Invitation::where('team_id', $team->id)->where('email', 'dup@example.test')->count())->toBe(1)
        ->and($model->id)->toBe($first->id)
        ->and($model->token)->not->toBe('old');
});

it('forbids a non-owner/admin from inviting', function () {
    [, $team] = ownerWithTeam();
    $member = User::create(['name' => 'M', 'email' => 'member@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);
    Auth::login($member->fresh());

    expect(fn () => InvitationResourceData::prepare(new Invitation, CreateInvitationData::from(['email' => 'x@example.test']), $member->fresh()))
        ->toThrow(HttpException::class);
});

it('scopes invitations to the current team and pending-only', function () {
    [, $team] = ownerWithTeam();
    Invitation::create(['team_id' => $team->id, 'email' => 'pending@example.test', 'role' => 'member', 'token' => 'p']);
    $accepted = Invitation::create(['team_id' => $team->id, 'email' => 'accepted@example.test', 'role' => 'member', 'token' => 'a']);
    $accepted->update(['accepted_at' => now()]);

    $rows = InvitationResourceData::scope(Invitation::query())->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->email)->toBe('pending@example.test');
});

it('excludes the owner from invitable roles', function () {
    expect(CreateInvitationData::invitableRoles())->toBe(['admin', 'member']);
});

// ── Members (source-backed) ───────────────────────────────────────────────────────────────────

it('streams the current team members through the source', function () {
    [$owner, $team] = ownerWithTeam();
    $member = User::create(['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);

    $page = app(MembershipSource::class)->index(new UnionQuery(perPage: 20, cursor: null));

    $emails = collect($page->items())->map(fn (MembershipResourceData $m) => $m->email)->sort()->values()->all();
    expect($emails)->toBe(['ann@example.test', 'owner@example.test']);
});

it('the member source is empty when there is no active team', function () {
    $stray = User::create(['name' => 'Stray', 'email' => 'stray@example.test', 'password' => 'x']);
    Auth::login($stray);

    expect(accountCurrentTeam())->toBeNull();
    $page = app(MembershipSource::class)->index(new UnionQuery(perPage: 20, cursor: null));
    expect($page->items())->toHaveCount(0);
});

// ── Teams (the domain-neutral tenant-admin list) ──────────────────────────────────────────────

it('projects a team into the admin list row', function () {
    [$owner, $team] = ownerWithTeam();
    $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);

    $row = TeamResourceData::project($team->fresh());

    expect($row->name)->toBe('T')
        ->and($row->ownerEmail)->toBe('owner@example.test')
        ->and($row->memberCount)->toBe(2)
        ->and($row->personal)->toBeTrue();
});

// ── OOTB registration (the "fresh host gets the area" claim) ──────────────────────────────────

it('registers tokens/invitations/members onto the Frame registries when beam is present', function () {
    // Bind the real beam registries; the provider's afterResolving hooks fire on first resolve.
    app()->singleton(AdminResourceRegistry::class, fn () => new AdminResourceRegistry);
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    // Re-run the boot hook now that the registries are bindable in this test app.
    (new BeamAccountsServiceProvider(app()))->boot();

    $admin = app(AdminResourceRegistry::class);
    $particles = app(ParticleResourceRegistry::class);

    // Admin/manifest side: all four list surfaces present.
    foreach (['tokens', 'invitations', 'members', 'teams'] as $key) {
        $admin->get($key); // throws if absent
    }
    expect($admin->get('members')->sourceKind)->toBe('service')
        ->and($admin->get('members')->deletable)->toBeFalse()
        ->and($admin->get('invitations')->editable)->toBeFalse()
        ->and($admin->get('tokens')->deletable)->toBeTrue();

    // REST/op side: the model-backed attribute resources are on the particle registry.
    expect($particles->has('tokens'))->toBeTrue()
        ->and($particles->has('invitations'))->toBeTrue()
        ->and($particles->has('teams'))->toBeTrue();
});

// ── Account profile (already package-owned — the 4th ticket-20 resource) ──────────────────────

it('owns the account-profile projection (name/email edit + access/roles/entitlements) OOTB', function () {
    // Profile edit input + the identity/access projection are already homed in beam-accounts —
    // the account-profile "resource" was promoted ahead of ticket 20; assert it stays package-owned.
    expect(class_exists(ProfileData::class))->toBeTrue()
        ->and(class_exists(ProfileUpdateInputData::class))->toBeTrue()
        ->and(class_exists(AuthUserData::class))->toBeTrue()
        ->and(class_exists(ProfileController::class))->toBeTrue();

    // The access projection carries roles + permissions (the entitlements axis a host extends via
    // the AuthUserExtrasContributor seam).
    $refl = new ReflectionClass(AuthUserData::class);
    $props = array_map(fn ($p) => $p->getName(), $refl->getConstructor()->getParameters());
    expect($props)->toContain('roles')->toContain('permissions');
});

it('the frame-resources seam is on by default and honours the disable flag', function () {
    // Default: the boot method registers (proven by the enabled test above).
    expect(config('beam.accounts.frame_resources.enabled', true))->toBeTrue();

    // Disabled: bootFrameResources short-circuits before touching any registry. A fresh, isolated
    // container (no setUp-armed afterResolving hook) proves the guard — a disabled boot arms nothing,
    // so a registry resolved from THAT container stays empty.
    $app = new Application(dirname(__DIR__));
    $app->instance('config', new Repository([
        'beam' => ['accounts' => ['frame_resources' => ['enabled' => false]]],
    ]));
    $app->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    $provider = new BeamAccountsServiceProvider($app);
    $boot = new ReflectionMethod($provider, 'bootFrameResources');
    $boot->setAccessible(true);
    $boot->invoke($provider);

    expect($app->make(ParticleResourceRegistry::class)->has('tokens'))->toBeFalse();
});
