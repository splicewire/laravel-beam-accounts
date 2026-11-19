<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * standwell gap-assessment-build 39, owner ruling 2026-11-19: a token minted or replaced by a call that is itself
 * authenticated with a scoped token can carry no ability outside the acting token's abilities. The account token routes
 * refuse more with `token_abilities_beyond_acting_token` (422, never trimmed); equal or narrower is minted; a session or
 * a `*` acting token (null scope) mints as before. The acting credential's scope is the cascade's
 * CredentialScopeResolver (beam-accounts' bearer resolver in a host), bound here to a fixed list.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->createPersonalAccessTokensSchema();
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();

    $owner = User::create(['name' => 'Owner', 'email' => 'cb39-mint@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);

    // Two permissions the owner really holds, so a refusal below is the acting-token rule, not the held clamp.
    app(PermissionRegistrar::class)->setPermissionsTeamId($team->getKey());
    foreach (['cb39.read', 'cb39.write'] as $name) {
        app(config('permission.models.permission'))::findOrCreate($name, 'web');
    }
    $owner->givePermissionTo(['cb39.read', 'cb39.write']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->owner = $owner->fresh();
    Auth::login($this->owner);
});

/** @param  list<string>|null  $scope  the acting credential's abilities; null is a session or a `*` token */
function cb39ActingScope(?array $scope): void
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

/** A stored token row of the owner with `$abilities`, to rotate. */
function cb39OwnedToken(object $owner, string $name, array $abilities): object
{
    $model = BeamAccounts::tokenModel();
    $token = (new $model)->forceFill([
        'tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->getKey(), 'name' => $name,
        'token' => hash('sha256', $name), 'abilities' => $abilities,
    ]);
    $token->save();

    return $token;
}

it('refuses a scoped token minting a wider token, coded and with nothing minted', function () {
    cb39ActingScope(['cb39.read']);
    $before = BeamAccounts::tokenModel()::count();

    $this->postJson('/beam/accounts/tokens', ['name' => 'x', 'abilities' => ['cb39.read', 'cb39.write']])
        ->assertStatus(422)->assertJsonPath('codes.abilities.0', 'token_abilities_beyond_acting_token');
    $this->postJson('/beam/accounts/tokens', ['name' => 'x', 'abilities' => ['*']])
        ->assertStatus(422)->assertJsonPath('codes.abilities.0', 'token_abilities_beyond_acting_token');
    $this->postJson('/beam/accounts/tokens', ['name' => 'x'])
        ->assertStatus(422)->assertJsonPath('codes.abilities.0', 'token_abilities_beyond_acting_token');

    expect(BeamAccounts::tokenModel()::count())->toBe($before);
});

it('lets a scoped token mint an equal or narrower token', function () {
    cb39ActingScope(['cb39.read', 'cb39.write']);

    $this->postJson('/beam/accounts/tokens', ['name' => 'eq', 'abilities' => ['cb39.read', 'cb39.write']])->assertCreated();
    $id = $this->postJson('/beam/accounts/tokens', ['name' => 'narrow', 'abilities' => ['cb39.read']])->assertCreated()->json('data.id');

    expect(BeamAccounts::tokenModel()::findOrFail($id)->abilities)->toBe(['cb39.read']);
});

it('leaves a session or * token mint unchanged', function () {
    cb39ActingScope(null);

    $this->postJson('/beam/accounts/tokens', ['name' => 'all'])->assertCreated();
    $this->postJson('/beam/accounts/tokens', ['name' => 'some', 'abilities' => ['cb39.read', 'cb39.write']])->assertCreated();
});

it('refuses a scoped token rotating a wider token and rotates one within it', function () {
    $wide = cb39OwnedToken($this->owner, 'wide', ['*']);
    $narrow = cb39OwnedToken($this->owner, 'narrow', ['cb39.read']);

    cb39ActingScope(['cb39.read']);
    $this->postJson("/beam/accounts/tokens/{$wide->getKey()}/rotate", [])
        ->assertStatus(422)->assertJsonPath('codes.abilities.0', 'token_abilities_beyond_acting_token');
    expect($wide->fresh()->archived_at)->toBeNull();
    $this->postJson("/beam/accounts/tokens/{$narrow->getKey()}/rotate", [])->assertCreated();

    cb39ActingScope(null);
    $this->postJson("/beam/accounts/tokens/{$wide->getKey()}/rotate", [])->assertCreated();
});
