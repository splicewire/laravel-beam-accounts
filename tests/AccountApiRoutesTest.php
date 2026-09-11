<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Data\TokenData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\AltPersonalAccessToken;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The ACCOUNT-TIER REST survivors — `Route::splicewireAccountApiRoutes()` and the three controllers
 * behind it.
 *
 * ## What this suite is the witness for
 *
 * Two declarations in this package were served by NOTHING until these controllers shipped:
 * `ApiTokenData` (the token read-model) and `CreatedTokenData` (the reveal-once mint response). The
 * `tokens` particle resource is `readOnly: true` by design and its docblock names create as a "HOST
 * escape hatch"; measured 2026-09-11 across the beam starter family, no host had ever wired one, so
 * `/tokens` rendered a list nothing could put a row in. Every assertion below is about that gap
 * being closed without moving the isolation boundary that was already correct.
 *
 * ## The gate is CLOSED, and the negatives are the proof
 *
 * Nothing here installs a blanket `Gate::before`. The cross-principal cases — another user's token
 * answering 404 rather than 403, a member's invite answering 403, the last owner refusing to be
 * demoted — only mean something under a closed gate, and they are what says this suite ran rather
 * than passed by not running.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->createPersonalAccessTokensSchema();

    // The macro is a MACRO ONLY — nothing in the package mounts it, deliberately (see
    // `routes/account-api.php`). A host calls it; so does this suite, which is also the assertion
    // that the default prefix and the default middleware are the ones a bare call gets.
    Route::splicewireAccountApiRoutes();

    // `RouteCollection` builds its name lookup lazily, on the first dispatch. Registering routes
    // after the app has booted — which is what a test doing the host's job looks like — leaves
    // `Route::has()` answering false for routes that are mounted and dispatch correctly. Refreshing
    // is the instrument catching up with reality, not a change to it; without this line the
    // name-coverage assertion below would report an absence that is purely an artefact of when the
    // mount happened.
    Route::getRoutes()->refreshNameLookups();
});

/** Provision a team-owner user + their personal team, signed in. */
function apiOwner(string $email = 'owner@example.test'): array
{
    $owner = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());

    return [$owner->fresh(), $team];
}

/** Seat a second user on an existing team at a given role. */
function apiSeat(Team $team, string $email, Role $role): User
{
    $user = User::create(['name' => $email, 'email' => $email, 'password' => 'x']);
    Membership::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => $role->value]);
    $user->switchTeam($team);

    return $user->fresh();
}

// ── The mount itself ────────────────────────────────────────────────────────────────────────

it('mounts the whole family under the configured api root with canonical route names', function () {
    // Named exactly as `~/Herd/splicewire-app` already mounts them, because
    // `@splicewire/beam-accounts`' TokensClient/TeamClient address these names and must keep
    // working at either host.
    foreach ([
        'beam.accounts.tokens.index', 'beam.accounts.tokens.store', 'beam.accounts.tokens.permissions', 'beam.accounts.tokens.renew',
        'beam.accounts.tokens.rotate', 'beam.accounts.tokens.sessions.others',
        'beam.accounts.tokens.destroy', 'beam.accounts.tokens.archive',
        'beam.accounts.members.index', 'beam.accounts.members.roles',
        'beam.accounts.members.update-role', 'beam.accounts.members.remove',
        'beam.accounts.invitations.index', 'beam.accounts.invitations.send',
        'beam.accounts.invitations.resend', 'beam.accounts.invitations.revoke',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("route [{$name}] is not mounted");
    }

    expect(route('beam.accounts.tokens.index', absolute: false))->toBe('/beam/accounts/tokens');
});

it('puts the literal sub-resource segments ahead of {id}', function () {
    // `sessions/others` and `{id}/permanent` must not be read as an id — the ordering is the whole
    // reason those two lines sit where they do in routes/account-api.php.
    expect(route('beam.accounts.tokens.sessions.others', absolute: false))->toBe('/beam/accounts/tokens/sessions/others')
        ->and(app('router')->getRoutes()->match(
            Request::create('/beam/accounts/tokens/sessions/others', 'DELETE')
        )->getName())->toBe('beam.accounts.tokens.sessions.others');
});

// ── Tokens ──────────────────────────────────────────────────────────────────────────────────

it('mints a token and reveals the plaintext exactly once', function () {
    apiOwner();

    $response = $this->postJson('/beam/accounts/tokens', ['name' => 'ci']);

    $response->assertCreated();
    $plain = $response->json('data.token');

    expect($plain)->toBeString()->not->toBeEmpty()
        ->and($response->json('data.name'))->toBe('ci');

    // The stored column is a HASH of the secret's entropy half, never the secret — which is why the
    // reveal can only happen in this one response.
    $row = PersonalAccessToken::firstWhere('name', 'ci');
    expect($row->token)->not->toBe($plain)
        ->and($row->provenance)->toBe(TokenProvenance::Api);

    // The list never carries it again.
    expect($this->getJson('/beam/accounts/tokens')->json('data.0'))->not->toHaveKey('token');
});

it('mints a token Sanctum can resolve back to its owner', function () {
    [$owner] = apiOwner();

    $plain = $this->postJson('/beam/accounts/tokens', ['name' => 'bearer'])->json('data.token');

    // `findToken` is the resolution Sanctum's guard performs on a Bearer header. If the mint's
    // `id|plaintext` shape or its hash were wrong, this is where it shows.
    $resolved = PersonalAccessToken::findToken($plain);

    expect($resolved)->not->toBeNull()
        ->and((string) $resolved->tokenable_id)->toBe((string) $owner->getKey());
});

it('mints through the CONFIGURED token model, not Sanctum\'s global', function () {
    apiOwner();

    // A host with a bespoke PAT binds `beam.accounts.tokens.model`. The mint must follow that seam —
    // the same one every read on this surface uses. `$user->createToken()` would have written
    // `Sanctum::personalAccessTokenModel()` instead, so the row would land in a table this list
    // never opens and the token would be invisible to the surface that minted it.
    Schema::create('alt_personal_access_tokens', function (Blueprint $table): void {
        $table->id();
        $table->string('tokenable_type');
        $table->uuid('tokenable_id');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->string('provenance')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->timestamps();
    });

    config(['beam.accounts.tokens.model' => AltPersonalAccessToken::class]);

    $this->postJson('/beam/accounts/tokens', ['name' => 'elsewhere'])->assertCreated();

    expect(AltPersonalAccessToken::where('name', 'elsewhere')->count())->toBe(1)
        ->and(PersonalAccessToken::count())->toBe(0)
        // And the read follows it too, so mint and list cannot disagree.
        ->and(collect($this->getJson('/beam/accounts/tokens')->json('data'))->pluck('name')->all())
        ->toBe(['elsewhere']);
});

it('offers the scoped-create picker exactly the permissions the clamp will accept', function () {
    [$owner] = apiOwner();

    $offered = $this->getJson('/beam/accounts/tokens/permissions')->json('data.permissions');

    // The picker's vocabulary and the mint's clamp must be one list, or the UI offers a scope the
    // server refuses. Asserted by construction: everything offered mints, and a name that is not
    // offered is a 422.
    expect($offered)->toBe($owner->getAllPermissions()->pluck('name')->all());

    $this->postJson('/beam/accounts/tokens', ['name' => 'unheld', 'abilities' => ['definitely.not.held']])
        ->assertStatus(422);
});

it('lists only the acting principal\'s own tokens', function () {
    [$owner] = apiOwner();
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

    $this->postJson('/beam/accounts/tokens', ['name' => 'mine'])->assertCreated();

    $theirs = new PersonalAccessToken(['name' => 'theirs', 'token' => hash('sha256', 'x')]);
    $theirs->tokenable_type = $other->getMorphClass();
    $theirs->tokenable_id = $other->id;
    $theirs->save();

    $names = collect($this->getJson('/beam/accounts/tokens')->json('data'))->pluck('name');

    expect($names->all())->toBe(['mine']);
});

it('refuses to archive another principal\'s token with 404, not 403', function () {
    apiOwner();
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

    $theirs = new PersonalAccessToken(['name' => 'theirs', 'token' => hash('sha256', 'y')]);
    $theirs->tokenable_type = $other->getMorphClass();
    $theirs->tokenable_id = $other->id;
    $theirs->save();

    // 404 rather than 403 deliberately: a 403 would confirm the id exists on a table every principal
    // shares. The scope is `TokenData::scope()`, the same closure the Frame list and revoke ride.
    $this->deleteJson("/beam/accounts/tokens/{$theirs->id}")->assertNotFound();

    expect($theirs->fresh()->archived_at)->toBeNull();
});

it('archives a token: the secret stops working and the row survives for audit', function () {
    apiOwner();

    $created = $this->postJson('/beam/accounts/tokens', ['name' => 'revoke-me'])->json('data');
    $plain = $created['token'];

    expect(PersonalAccessToken::findToken($plain))->not->toBeNull();

    $this->deleteJson("/beam/accounts/tokens/{$created['id']}")->assertOk();

    // The stored hash is overwritten, so the dead secret can never match again — this is what makes
    // "revoked credential unusable" true rather than merely flagged.
    expect(PersonalAccessToken::findToken($plain))->toBeNull()
        ->and(PersonalAccessToken::find($created['id'])->archived_at)->not->toBeNull();

    // Archiving twice is a 409, not a second success.
    $this->deleteJson("/beam/accounts/tokens/{$created['id']}")->assertStatus(409);
});

it('permanently deletes only an already-archived token', function () {
    apiOwner();

    $created = $this->postJson('/beam/accounts/tokens', ['name' => 'gone'])->json('data');

    $this->deleteJson("/beam/accounts/tokens/{$created['id']}/permanent")
        ->assertStatus(422);

    $this->deleteJson("/beam/accounts/tokens/{$created['id']}")->assertOk();
    $this->deleteJson("/beam/accounts/tokens/{$created['id']}/permanent")->assertOk();

    expect(PersonalAccessToken::find($created['id']))->toBeNull();
});

it('clamps a requested scope to permissions the minting principal holds', function () {
    apiOwner();

    $this->postJson('/beam/accounts/tokens', ['name' => 'scoped', 'abilities' => ['nothing.i.hold']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('abilities');

    // An omitted list is the unscoped default — you act fully as yourself.
    $this->postJson('/beam/accounts/tokens', ['name' => 'full'])->assertCreated();
    expect(PersonalAccessToken::firstWhere('name', 'full')->abilities)->toBe(['*']);
});

it('takes the wire spelling expires_in_days, not the camelCase property name', function () {
    apiOwner();

    $this->postJson('/beam/accounts/tokens', ['name' => 'dated', 'expires_in_days' => 7])->assertCreated();

    expect(PersonalAccessToken::firstWhere('name', 'dated')->expires_at)->not->toBeNull();
});

it('sweeps other SESSION tokens and leaves deliberate API tokens working', function () {
    [$owner] = apiOwner();

    $this->postJson('/beam/accounts/tokens', ['name' => 'integration'])->assertCreated();

    foreach (['s1', 's2'] as $seed) {
        $session = new PersonalAccessToken(['name' => $seed, 'token' => hash('sha256', $seed)]);
        $session->tokenable_type = $owner->getMorphClass();
        $session->tokenable_id = $owner->id;
        $session->provenance = TokenProvenance::Session->value;
        $session->save();
    }

    $response = $this->deleteJson('/beam/accounts/tokens/sessions/others');

    expect($response->json('data.revoked'))->toBe(2)
        ->and(PersonalAccessToken::firstWhere('name', 'integration'))->not->toBeNull();
});

it('agrees with the frame list about which rows exist', function () {
    apiOwner();
    $this->postJson('/beam/accounts/tokens', ['name' => 'shared-boundary'])->assertCreated();

    // One boundary, two transports: the REST index and the resource's own scope must see the same
    // rows, or a host that binds `beam.accounts.tokens.scope` gets a console that disagrees with
    // its own API.
    $rest = collect($this->getJson('/beam/accounts/tokens')->json('data'))->pluck('id')->map(strval(...));
    $frame = TokenData::scope(PersonalAccessToken::query())->pluck('id')->map(strval(...));

    expect($rest->sort()->values()->all())->toBe($frame->sort()->values()->all());
});

// ── Members ─────────────────────────────────────────────────────────────────────────────────

it('lists members and the server-derived role vocabulary', function () {
    [, $team] = apiOwner();
    apiSeat($team, 'member@example.test', Role::Member);

    $emails = collect($this->getJson('/beam/accounts/members')->json('data'))->pluck('email');
    expect($emails)->toContain('owner@example.test', 'member@example.test');

    $roles = $this->getJson('/beam/accounts/members/roles')->json('data');
    expect(collect($roles['assignable'])->pluck('value')->all())->toBe(Role::values())
        ->and(collect($roles['invitable'])->pluck('value')->all())->toBe(Role::invitableValues());
});

it('lets an owner change a member\'s role and refuses a member doing the same', function () {
    [, $team] = apiOwner();
    $member = apiSeat($team, 'member@example.test', Role::Member);

    $this->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'admin'])->assertOk();
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Admin);

    Auth::login($member->fresh());
    $this->putJson("/beam/accounts/members/{$member->id}/role", ['role' => 'owner'])->assertForbidden();
    expect($team->fresh()->memberRole($member->fresh()))->toBe(Role::Admin);
});

it('refuses to demote or remove the last owner', function () {
    [$owner] = apiOwner();

    // A well-formed request whose outcome is not permitted — 422, not 403: the actor IS the owner.
    $this->putJson("/beam/accounts/members/{$owner->id}/role", ['role' => 'member'])->assertStatus(422);
    $this->deleteJson("/beam/accounts/members/{$owner->id}")->assertStatus(422);
});

it('lets an owner remove a member', function () {
    [, $team] = apiOwner();
    $member = apiSeat($team, 'member@example.test', Role::Member);

    $this->deleteJson("/beam/accounts/members/{$member->id}")->assertOk();

    expect($team->fresh()->hasMember($member->fresh()))->toBeFalse();
});

it('refuses a non-member of the current team outright', function () {
    apiOwner();
    $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'x']);
    Auth::login($outsider);

    // No team at all for this principal ⇒ 403 from the resolver guard, never an empty 200 that
    // reads as "this team has no members".
    $this->getJson('/beam/accounts/members')->assertForbidden();
});

// ── Invitations ─────────────────────────────────────────────────────────────────────────────

it('sends an invitation, lists it pending, and revokes it', function () {
    apiOwner();

    $sent = $this->postJson('/beam/accounts/invitations', ['email' => 'new@example.test', 'role' => 'member']);
    $sent->assertCreated();

    $id = $sent->json('data.id');
    expect($sent->json('data.acceptedAt'))->toBeNull();

    // Durable after a fresh read — the row, not a client-side optimism.
    expect(collect($this->getJson('/beam/accounts/invitations')->json('data'))->pluck('email')->all())
        ->toBe(['new@example.test']);

    $this->deleteJson("/beam/accounts/invitations/{$id}")->assertOk();

    expect($this->getJson('/beam/accounts/invitations')->json('data'))->toBe([])
        ->and(Invitation::count())->toBe(0);
});

it('re-invites an existing address as an UPDATE, never a unique-key collision', function () {
    apiOwner();

    $first = $this->postJson('/beam/accounts/invitations', ['email' => 'dup@example.test', 'role' => 'member']);
    $first->assertCreated();
    $firstToken = Invitation::firstWhere('email', 'dup@example.test')->token;

    $second = $this->postJson('/beam/accounts/invitations', ['email' => 'dup@example.test', 'role' => 'admin']);
    $second->assertCreated();

    expect(Invitation::where('email', 'dup@example.test')->count())->toBe(1)
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.role'))->toBe('admin')
        // A re-invite mints a FRESH bearer token, which is what makes resend meaningful.
        ->and(Invitation::firstWhere('email', 'dup@example.test')->token)->not->toBe($firstToken);
});

it('resends a pending invitation with a fresh token', function () {
    apiOwner();

    $id = $this->postJson('/beam/accounts/invitations', ['email' => 'again@example.test', 'role' => 'member'])
        ->json('data.id');
    $before = Invitation::find($id)->token;

    $this->postJson("/beam/accounts/invitations/{$id}/resend")->assertOk();

    expect(Invitation::find($id)->token)->not->toBe($before);
});

it('refuses a member sending, resending or revoking an invitation', function () {
    [, $team] = apiOwner();
    $id = $this->postJson('/beam/accounts/invitations', ['email' => 'x@example.test', 'role' => 'member'])
        ->json('data.id');

    $member = apiSeat($team, 'member@example.test', Role::Member);
    Auth::login($member);

    $this->postJson('/beam/accounts/invitations', ['email' => 'y@example.test', 'role' => 'member'])->assertForbidden();
    $this->postJson("/beam/accounts/invitations/{$id}/resend")->assertForbidden();
    $this->deleteJson("/beam/accounts/invitations/{$id}")->assertForbidden();

    expect(Invitation::find($id))->not->toBeNull();
});

it('refuses an invalid email with 422 and creates nothing', function () {
    apiOwner();

    $this->postJson('/beam/accounts/invitations', ['email' => 'not-an-email', 'role' => 'member'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(Invitation::count())->toBe(0);
});

it('never resolves an accepted or foreign-team invitation for revoke', function () {
    [, $team] = apiOwner();

    $accepted = Invitation::create([
        'team_id' => (string) $team->id,
        'email' => 'joined@example.test',
        'role' => 'member',
        'token' => 'tok-accepted',
        'accepted_at' => now(),
    ]);

    $foreign = Invitation::create([
        'team_id' => 'some-other-team',
        'email' => 'elsewhere@example.test',
        'role' => 'member',
        'token' => 'tok-foreign',
    ]);

    $this->deleteJson("/beam/accounts/invitations/{$accepted->id}")->assertNotFound();
    $this->deleteJson("/beam/accounts/invitations/{$foreign->id}")->assertNotFound();

    expect(Invitation::find($accepted->id))->not->toBeNull()
        ->and(Invitation::find($foreign->id))->not->toBeNull();
});
