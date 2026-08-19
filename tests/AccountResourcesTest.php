<?php

use Illuminate\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\Contracts\UnionQuery;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\CreateInvitationData;
use Splicewire\Beam\Accounts\Data\InvitationData;
use Splicewire\Beam\Accounts\Data\MembershipData;
use Splicewire\Beam\Accounts\Data\ProfileData;
use Splicewire\Beam\Accounts\Data\ProfileUpdateInputData;
use Splicewire\Beam\Accounts\Data\TeamData;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Data\UserData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Frame\Sources\MembershipSource;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\ProfileController;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\QueryBuilders\TokensQuery;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;
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
    expect(BeamAccounts::tokenModel())->toBe(PersonalAccessToken::class);
});

it('scopes the token list to the acting user — never leaks another user\'s tokens', function () {
    [$owner] = ownerWithTeam();
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

    mintToken($owner, 'mine', 'a');
    mintToken($other, 'theirs', 'b');

    $rows = TokenData::scope(PersonalAccessToken::query())->get();

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

    expect(TokenData::scope(PersonalAccessToken::query())->count())->toBe(0);
});

it('projects a token into the frame row shape', function () {
    [$owner] = ownerWithTeam();
    $token = mintToken($owner, 'ci', 'd');

    $row = TokenData::project($token);

    expect($row->id)->toBe((string) $token->id)
        ->and($row->name)->toBe('ci')
        ->and($row->lastUsedAt)->toBeNull();
});

// ── Invitations ─────────────────────────────────────────────────────────────────────────────

it('prepares a new invitation for the current team as an owner', function () {
    [$owner, $team] = ownerWithTeam();
    $invitation = new Invitation;

    InvitationData::prepare($invitation, CreateInvitationData::from(['email' => 'new@example.test', 'role' => 'member']), $owner);

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
    InvitationData::prepare($model, CreateInvitationData::from(['email' => 'dup@example.test', 'role' => 'admin']), $owner);
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

    expect(fn () => InvitationData::prepare(new Invitation, CreateInvitationData::from(['email' => 'x@example.test']), $member->fresh()))
        ->toThrow(HttpException::class);
});

it('scopes invitations to the current team and pending-only', function () {
    [, $team] = ownerWithTeam();
    Invitation::create(['team_id' => $team->id, 'email' => 'pending@example.test', 'role' => 'member', 'token' => 'p']);
    $accepted = Invitation::create(['team_id' => $team->id, 'email' => 'accepted@example.test', 'role' => 'member', 'token' => 'a']);
    $accepted->update(['accepted_at' => now()]);

    $rows = InvitationData::scope(Invitation::query())->get();

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

    $emails = collect($page->items())->map(fn (MembershipData $m) => $m->email)->sort()->values()->all();
    expect($emails)->toBe(['ann@example.test', 'owner@example.test']);
});

it('the member source is empty when there is no active team', function () {
    $stray = User::create(['name' => 'Stray', 'email' => 'stray@example.test', 'password' => 'x']);
    Auth::login($stray);

    expect(BeamAccounts::currentTeam())->toBeNull();
    $page = app(MembershipSource::class)->index(new UnionQuery(perPage: 20, cursor: null));
    expect($page->items())->toHaveCount(0);
});

// ── Teams (the domain-neutral tenant-admin list) ──────────────────────────────────────────────

it('projects a team into the admin list row', function () {
    [$owner, $team] = ownerWithTeam();
    $member = User::create(['name' => 'M', 'email' => 'm@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $member->id, 'role' => Role::Member->value]);

    $row = TeamData::project($team->fresh());

    expect($row->name)->toBe('T')
        ->and($row->ownerEmail)->toBe('owner@example.test')
        ->and($row->memberCount)->toBe(2)
        ->and($row->personal)->toBeTrue();
});

// ── Users (the identity roster) ───────────────────────────────────────────────────────────────

it('projects a user into the admin list row', function () {
    [$owner] = ownerWithTeam();
    SpatieRole::create(['name' => Role::Admin->value, 'guard_name' => 'web']);
    $owner->assignRole(Role::Admin->value);

    $row = UserData::project($owner->fresh());

    expect($row->id)->toBe((string) $owner->id)
        ->and($row->name)->toBe('Owner')
        ->and($row->email)->toBe('owner@example.test')
        ->and($row->roles)->toContain(Role::Admin->value)
        // ISO-8601, not raw Carbon — the projection contract every sibling resource holds.
        ->and($row->createdAt)->toBeString()
        ->and($row->createdAt)->toMatch('/^\d{4}-\d{2}-\d{2}T/');
});

it('scopes the user list to teams the actor shares — never the whole roster', function () {
    [$owner, $team] = ownerWithTeam();
    $teammate = User::create(['name' => 'Mate', 'email' => 'mate@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'role' => Role::Member->value]);

    // A principal on an entirely separate team — the leak this scope exists to prevent.
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'x']);
    $otherTeam = Team::create(['user_id' => $stranger->id, 'name' => 'Other', 'personal_team' => true]);
    Membership::create(['team_id' => $otherTeam->id, 'user_id' => $stranger->id, 'role' => Role::Owner->value]);

    $emails = UserData::scope(User::query())->pluck('email')->sort()->values()->all();

    expect($emails)->toBe(['mate@example.test', 'owner@example.test'])
        ->and($emails)->not->toContain('stranger@example.test');
});

it('resolves the acting principal even with no team seat at all', function () {
    $loner = User::create(['name' => 'Loner', 'email' => 'loner@example.test', 'password' => 'x']);
    Auth::login($loner);

    $emails = UserData::scope(User::query())->pluck('email')->all();

    expect($emails)->toBe(['loner@example.test']);
});

it('the same scope governs the per-record read, so a detail cannot resolve a hidden user', function () {
    [$owner] = ownerWithTeam();
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'x']);
    $otherTeam = Team::create(['user_id' => $stranger->id, 'name' => 'Other', 'personal_team' => true]);
    Membership::create(['team_id' => $otherTeam->id, 'user_id' => $stranger->id, 'role' => Role::Owner->value]);

    // Frame carries the resource scope onto subject resolution; assert the boundary directly —
    // a list-only scope would let this resolve, which is the bug the shared closure prevents.
    $resolved = UserData::scope(User::query())->whereKey($stranger->id)->first();

    expect($resolved)->toBeNull()
        ->and(UserData::scope(User::query())->whereKey($owner->id)->first())->not->toBeNull();
});

it('a central Root principal sees every user', function () {
    [$owner] = ownerWithTeam();
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@example.test', 'password' => 'x']);
    $otherTeam = Team::create(['user_id' => $stranger->id, 'name' => 'Other', 'personal_team' => true]);
    Membership::create(['team_id' => $otherTeam->id, 'user_id' => $stranger->id, 'role' => Role::Owner->value]);

    // Root is assigned on the CENTRAL (null) team — the flip BeamAccounts::isRoot() exists to handle.
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    SpatieRole::create(['name' => 'Root', 'guard_name' => 'web']);
    $owner->assignRole('Root');
    Auth::login($owner->fresh());

    $emails = UserData::scope(User::query())->pluck('email')->sort()->values()->all();

    expect($emails)->toContain('stranger@example.test')
        ->and($emails)->toContain('owner@example.test');
});

it('user scope fails safe (empty) for a guest', function () {
    ownerWithTeam();
    Auth::logout();

    expect(UserData::scope(User::query())->count())->toBe(0);
});

it('honours a host user-scope seam over the default', function () {
    [$owner, $team] = ownerWithTeam();
    $teammate = User::create(['name' => 'Mate', 'email' => 'mate@example.test', 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $teammate->id, 'role' => Role::Member->value]);

    // A host whose seats live elsewhere replaces the boundary wholesale.
    config(['beam.accounts.users.scope' => fn ($query, $user) => $query->where('email', 'mate@example.test')]);

    $emails = UserData::scope(User::query())->pluck('email')->all();

    expect($emails)->toBe(['mate@example.test']);
});

// ── OOTB registration (the "fresh host gets the area" claim) ──────────────────────────────────

it('registers tokens/invitations/members/teams/users onto the Frame registries when beam is present', function () {
    // Bind the real beam registry; the provider's afterResolving hook fires on first resolve.
    app()->singleton(ParticleResourceRegistry::class, fn () => new ParticleResourceRegistry);

    // Re-run register()+boot() now that the registry is bindable in this test app. A fresh
    // PackageServiceProvider instance must be register()ed before boot() — register() is where
    // configurePackage() initializes the provider's $package property boot() reads. Re-running
    // register() here is harmless/idempotent (same tolerance the estate already documents for a
    // provider that boots twice, e.g. BeamDoctorManifest::register()'s idempotent replace).
    $provider = new BeamAccountsServiceProvider(app());
    $provider->register();
    $provider->boot();

    $registry = app(ParticleResourceRegistry::class);

    // Frame-manifest side: all five list surfaces present.
    foreach (['tokens', 'invitations', 'members', 'teams', 'users'] as $key) {
        $registry->definition($key); // throws if absent
    }
    expect($registry->definition('members')->sourceKind)->toBe('service')
        ->and($registry->definition('members')->deletable)->toBeFalse()
        ->and($registry->definition('invitations')->editable)->toBeFalse()
        ->and($registry->definition('tokens')->deletable)->toBeTrue();

    // Users is UPDATE-ONLY: no create (registration mints users), no delete (destructive and
    // cascade-bearing, wants a password-confirmed flow), but edit IS open — the edit-independent
    // widening, so the self-service profile write is a declared particle rather than the parallel
    // non-particle surface it used to be. The narrowing gate is UserPolicy (self, or central Root).
    expect($registry->definition('users')->creatable)->toBeFalse()
        ->and($registry->definition('users')->editable)->toBeTrue()
        ->and($registry->definition('users')->deletable)->toBeFalse()
        ->and($registry->definition('users')->policy)->toBe(UserPolicy::class);

    // REST/op side: the model-backed attribute resources are on the same registry.
    expect($registry->has('tokens'))->toBeTrue()
        ->and($registry->has('invitations'))->toBeTrue()
        ->and($registry->has('teams'))->toBeTrue()
        ->and($registry->has('users'))->toBeTrue();
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
