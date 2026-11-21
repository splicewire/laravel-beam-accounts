<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Support\CredentialScope;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Tokens\TokenManagement;

/*
 * credential-scope 08, first part (l145 review F3): the token-management routes answered a scoped bearer with no decision.
 * A paired connected-site token renewed a separate `*` token from a 1-day expiry to never-expiring (probe P7b), and could
 * rotate, archive and delete tokens and sign the user out of every session.
 *
 * Real Sanctum bearers through the routes a host mounts (the tower starter's `routes/api.php:93-95` shape:
 * `auth:sanctum` around `splicewireAccountApiRoutes(prefix: 'beam/accounts', middleware: [])`). Enforcement is on the way
 * a host booted with it on is: the provider's enforcement link runs again with the config set, so the bearer resolver
 * is bound (the l145 test's trap was a runtime flag with the null resolver still bound). The first test proves the
 * token half is live by its coded refusal.
 */

beforeEach(function () {
    $this->app->register(Laravel\Sanctum\SanctumServiceProvider::class);
    $this->createPersonalAccessTokensSchema();
    Sanctum::$accessTokenAuthenticationCallback = null;
    config(['beam.accounts.api.scope_enforcement' => true]);
    cs08Boot();

    // A flat permission-shaped Gate definition is offerable, so a personal token may be scoped to it (the widest set).
    Illuminate\Support\Facades\Gate::define('entity.view', fn ($user = null) => $user !== null);
    Illuminate\Support\Facades\Gate::define('entity.delete', fn ($user = null) => $user !== null);

    Route::prefix('api/v1')->middleware(['auth:sanctum'])->group(
        fn () => Route::splicewireAccountApiRoutes(prefix: 'beam/accounts', middleware: [])
    );
    Route::getRoutes()->refreshNameLookups();

    $this->user = Cs08TokenUser::create(['name' => 'Ada', 'email' => 'ada-cs08@example.test', 'password' => 'x']);
});

afterEach(function () {
    Sanctum::$accessTokenAuthenticationCallback = null;
    config(['beam.accounts.api.scope_enforcement' => false]);
});

class Cs08TokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}

/** Run the provider's enforcement link with the current config, as a host booting with enforcement on does. */
function cs08Boot(): void
{
    $provider = new BeamAccountsServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootApiGuardEnforcement');
    $method->setAccessible(true);
    $method->invoke($provider);
    app()->forgetInstance(CredentialScope::class);
}

/** @return array{0: string, 1: PersonalAccessToken} plaintext + row */
function cs08Token(Cs08TokenUser $user, string $name, array $abilities, ?DateTimeInterface $expiresAt = null): array
{
    $new = $user->createToken($name, $abilities, $expiresAt); // test fixture rows; the issuer is not under test here

    return [$new->plainTextToken, $new->accessToken];
}

/** A session (login) token as AuthTokenFactory mints one, its provenance forced: Sanctum's base model does not fill it. */
function cs08Session(Cs08TokenUser $user, string $agent): string
{
    $new = AuthTokenFactory::mint($user, $agent, TokenProvenance::Session, false);
    $new->accessToken->forceFill(['provenance' => TokenProvenance::Session->value])->save();

    return $new->plainTextToken;
}

/** A fresh request as `$bearer`: no session, no guard memo from the previous request. */
function cs08As(string $bearer)
{
    app('auth')->forgetGuards();

    return test()->withToken($bearer);
}

/** Every token-management name, class level: what a token needs to reach every verb. */
function cs08AllNames(): array
{
    return ['personal-access-token.view', 'personal-access-token.create', 'personal-access-token.update', 'personal-access-token.delete'];
}

/** The persisted state a refusal must leave intact. */
function cs08State(): array
{
    return PersonalAccessToken::query()->orderBy('id')->get()
        ->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'abilities' => $t->abilities,
            'expires_at' => $t->expires_at?->toIso8601String(),
            'token' => $t->token,
            'archived_at' => $t->getAttribute('archived_at'),
        ])->all();
}

it('binds the bearer scope resolver, so the token half is live: a nameless scoped token gets the coded refusal', function () {
    expect(app(CredentialScopeResolver::class))->toBeInstanceOf(TokenAbilitiesScopeResolver::class);
    [$bearer] = cs08Token($this->user, 'nameless', ['entity.view']);

    cs08As($bearer)->getJson('/api/v1/beam/accounts/tokens')
        ->assertForbidden()
        ->assertJsonPath('code', 'credential_scope_name_not_carried');
});

it('registers a personal-access-token name for every token-management verb', function () {
    $names = app(Rushing\PermissionCascade\Authorization\DecisionNames::class);

    foreach (TokenManagement::VERBS as $decision => $verb) {
        expect($names->for($decision, []))->toBe(['personal-access-token.'.$verb]);
    }

    expect(app(Rushing\PermissionCascade\Authorization\CredentialScopeGate::class)->offerableNames())
        ->toContain(...cs08AllNames());
});

it('refuses every token-management verb to a scoped token lacking its name, with the coded 403 and nothing changed', function (string $method, string $uri, string $lacks) {
    [, $target] = cs08Token($this->user, 'target', ['entity.view'], now()->addDay());
    [, $archived] = cs08Token($this->user, 'archived', ['entity.view']);
    $archived->forceFill(['archived_at' => now()])->save();
    cs08Session($this->user, 'Browser/1.0');
    // The acting token carries every other token-management name, and not this verb's.
    $carried = array_values(array_diff([...cs08AllNames(), 'entity.view'], [$lacks]));
    [$bearer] = cs08Token($this->user, 'acting', $carried);
    $before = cs08State();

    $uri = str_replace(['{target}', '{archived}'], [$target->id, $archived->id], $uri);
    cs08As($bearer)->json($method, $uri, $method === 'POST' && str_ends_with($uri, 'tokens') ? ['name' => 'child'] : [])
        ->assertForbidden()
        ->assertJsonPath('code', 'credential_scope_name_not_carried');

    // Only the acting row's last_used_at may move; compare the rest.
    expect(cs08State())->toEqual($before);
})->with([
    'index' => ['GET', '/api/v1/beam/accounts/tokens', 'personal-access-token.view'],
    'permissions' => ['GET', '/api/v1/beam/accounts/tokens/permissions', 'personal-access-token.create'],
    'store' => ['POST', '/api/v1/beam/accounts/tokens', 'personal-access-token.create'],
    'renew' => ['POST', '/api/v1/beam/accounts/tokens/{target}/renew', 'personal-access-token.update'],
    'rotate' => ['POST', '/api/v1/beam/accounts/tokens/{target}/rotate', 'personal-access-token.update'],
    'archive' => ['DELETE', '/api/v1/beam/accounts/tokens/{target}', 'personal-access-token.delete'],
    'destroy' => ['DELETE', '/api/v1/beam/accounts/tokens/{archived}/permanent', 'personal-access-token.delete'],
    'sessions/others' => ['DELETE', '/api/v1/beam/accounts/tokens/sessions/others', 'personal-access-token.delete'],
]);

it('admits every token-management verb for a scoped token carrying its name, within its own authority', function () {
    [$bearer, $acting] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view']);
    [, $narrow] = cs08Token($this->user, 'narrow', ['entity.view'], now()->addDay());
    [, $other] = cs08Token($this->user, 'other', ['entity.view']);

    cs08As($bearer)->getJson('/api/v1/beam/accounts/tokens')->assertOk()->assertJsonCount(3, 'data');
    cs08As($bearer)->getJson('/api/v1/beam/accounts/tokens/permissions')->assertOk();
    cs08As($bearer)->postJson('/api/v1/beam/accounts/tokens', ['name' => 'child', 'abilities' => ['entity.view']])->assertCreated();

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/renew", ['expiresInDays' => 7])->assertOk();
    expect($narrow->fresh()->expires_at->isSameDay(now()->addDays(7)))->toBeTrue('a narrower token, renewed within the acting token\'s (never-expiring) life');

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/rotate", [])->assertCreated();
    expect($narrow->fresh()->getAttribute('archived_at'))->not->toBeNull();

    cs08As($bearer)->deleteJson("/api/v1/beam/accounts/tokens/{$other->id}")->assertOk();
    cs08As($bearer)->deleteJson("/api/v1/beam/accounts/tokens/{$other->id}/permanent")->assertOk();
    expect(PersonalAccessToken::find($other->id))->toBeNull();

    // No other session exists, so signing the others out revokes nothing and is admitted.
    cs08As($bearer)->deleteJson('/api/v1/beam/accounts/tokens/sessions/others')->assertOk()->assertJsonPath('data.revoked', 0);
    expect($acting->fresh())->not->toBeNull();
});

it('admits a verb on one token for the per-record name alone', function () {
    [, $narrow] = cs08Token($this->user, 'narrow', ['entity.view'], now()->addDay());
    [$bearer] = cs08Token($this->user, 'acting', ["personal-access-token.{$narrow->id}.update", 'entity.view']);
    [, $sibling] = cs08Token($this->user, 'sibling', ['entity.view'], now()->addDay());

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/renew", ['expiresInDays' => 3])->assertOk();
    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$sibling->id}/renew", ['expiresInDays' => 3])
        ->assertForbidden()->assertJsonPath('code', 'credential_scope_name_not_carried');
});

it('refuses P7b: a scoped token renewing a separate * token from a 1-day expiry to never-expiring, state unchanged', function () {
    // The paired token of the l145 review held the widest set, every token-management name included.
    [$bearer] = cs08Token($this->user, 'paired', [...cs08AllNames(), 'entity.view']);
    [, $star] = cs08Token($this->user, 'other-short', ['*'], now()->addDay());
    $before = cs08State();

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$star->id}/renew", [])
        ->assertForbidden()
        ->assertJsonPath('code', 'token_beyond_acting_token');

    expect(cs08State())->toEqual($before)
        ->and($star->fresh()->expires_at->isSameDay(now()->addDay()))->toBeTrue()
        ->and($star->fresh()->abilities)->toBe(['*']);
});

it('refuses a renewal past the acting token\'s own expiry, never included, and admits one within it', function () {
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view'], now()->addDays(2));
    [, $narrow] = cs08Token($this->user, 'narrow', ['entity.view'], now()->addHour());
    $before = cs08State();

    foreach ([['expiresInDays' => 30], []] as $body) {
        cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/renew", $body)
            ->assertUnprocessable()
            ->assertJsonPath('codes.expiresInDays.0', 'token_expiry_beyond_acting_token');
        expect(cs08State())->toEqual($before);
    }

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/renew", ['expiresInDays' => 1])->assertOk();
    expect($narrow->fresh()->expires_at->isSameDay(now()->addDay()))->toBeTrue();
});

it('refuses renewing or rotating a token wider than the acting one, and leaves both rows alone', function () {
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view']);
    [, $wider] = cs08Token($this->user, 'wider', ['entity.view', 'entity.delete'], now()->addDay());
    $before = cs08State();

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$wider->id}/renew", ['expiresInDays' => 1])
        ->assertForbidden()->assertJsonPath('code', 'token_beyond_acting_token');
    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$wider->id}/rotate", [])
        ->assertForbidden()->assertJsonPath('code', 'token_beyond_acting_token');

    expect(cs08State())->toEqual($before);
});

it('refuses rotating a * token from a scoped token: nothing issued, the old row not archived', function () {
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view']);
    [, $star] = cs08Token($this->user, 'legacy-star', ['*']);
    $before = cs08State();

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$star->id}/rotate", [])
        ->assertForbidden()->assertJsonPath('code', 'token_beyond_acting_token');

    expect(cs08State())->toEqual($before);
});

it('refuses a rotation or a mint that would outlive the acting token, before anything is written', function () {
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view'], now()->addDays(2));
    [, $narrow] = cs08Token($this->user, 'narrow', ['entity.view'], now()->addHour());
    $before = cs08State();

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/rotate", ['expiresInDays' => 30])
        ->assertUnprocessable()->assertJsonPath('codes.expiresInDays.0', 'token_expiry_beyond_acting_token');
    cs08As($bearer)->postJson('/api/v1/beam/accounts/tokens', ['name' => 'child', 'abilities' => ['entity.view']])
        ->assertUnprocessable()->assertJsonPath('codes.expiresInDays.0', 'token_expiry_beyond_acting_token');

    expect(cs08State())->toEqual($before);

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/rotate", ['expiresInDays' => 1])->assertCreated();
});

it('refuses archiving or deleting a * token, and signing out the other sessions, from a scoped token', function () {
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view']);
    [, $star] = cs08Token($this->user, 'star', ['*']);
    [, $archivedStar] = cs08Token($this->user, 'archived-star', ['*']);
    $archivedStar->forceFill(['archived_at' => now()])->save();
    cs08Session($this->user, 'Browser/1.0');
    $before = cs08State();

    cs08As($bearer)->deleteJson("/api/v1/beam/accounts/tokens/{$star->id}")
        ->assertForbidden()->assertJsonPath('code', 'token_beyond_acting_token');
    cs08As($bearer)->deleteJson("/api/v1/beam/accounts/tokens/{$archivedStar->id}/permanent")
        ->assertForbidden()->assertJsonPath('code', 'token_beyond_acting_token');
    cs08As($bearer)->deleteJson('/api/v1/beam/accounts/tokens/sessions/others')
        ->assertForbidden()->assertJsonPath('code', 'token_beyond_acting_token');

    expect(cs08State())->toEqual($before);
});

it('leaves a * login token and a session unchanged: they renew a * token to never and sign the others out', function () {
    $login = cs08Session($this->user, 'Probe/1.0');
    [, $star] = cs08Token($this->user, 'star', ['*'], now()->addDay());
    cs08Session($this->user, 'Browser/2.0');

    cs08As($login)->postJson("/api/v1/beam/accounts/tokens/{$star->id}/renew", [])->assertOk();
    expect($star->fresh()->expires_at)->toBeNull();

    cs08As($login)->deleteJson('/api/v1/beam/accounts/tokens/sessions/others')->assertOk()->assertJsonPath('data.revoked', 1);

    $star->forceFill(['expires_at' => now()->addDay()])->save();
    app('auth')->forgetGuards();
    $this->withoutToken()->actingAs($this->user)->postJson("/api/v1/beam/accounts/tokens/{$star->id}/renew", [])->assertOk();
    expect($star->fresh()->expires_at)->toBeNull();
});

it('takes Sanctum\'s global expiration as the acting token\'s expiry when it is sooner', function () {
    config(['sanctum.expiration' => 120]);
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view']);
    [, $narrow] = cs08Token($this->user, 'narrow', ['entity.view'], now()->addHour());
    $before = cs08State();

    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$narrow->id}/renew", ['expiresInDays' => 1])
        ->assertUnprocessable()->assertJsonPath('codes.expiresInDays.0', 'token_expiry_beyond_acting_token');

    expect(cs08State())->toEqual($before);
});

it('refuses, at the issuer, a scoped token managing another principal\'s row (the owner scope 404s it first over HTTP)', function () {
    $other = Cs08TokenUser::create(['name' => 'Bea', 'email' => 'bea-cs08@example.test', 'password' => 'x']);
    [, $theirs] = cs08Token($other, 'theirs', ['entity.view'], now()->addDay());
    [$bearer] = cs08Token($this->user, 'acting', [...cs08AllNames(), 'entity.view']);
    // Over HTTP the owner scope answers first.
    cs08As($bearer)->postJson("/api/v1/beam/accounts/tokens/{$theirs->id}/renew", [])->assertNotFound();

    // At the issuer, under the same bearer's scope, the row is refused as another principal's.
    $this->app->instance('request', Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]));
    $issuer = app(Splicewire\Beam\Accounts\Tokens\MachineTokenIssuer::class);
    $before = cs08State();
    $code = function (Closure $call): ?string {
        try {
            $call();
        } catch (Splicewire\Beam\Accounts\Localization\AccountHttpException $e) {
            return $e->error->code();
        }

        return null;
    };

    expect($code(fn () => $issuer->renew($theirs, new Splicewire\Beam\Accounts\Tokens\TokenIssuance(actor: $this->user, name: 'theirs', expiresAt: now()->addHour()))))
        ->toBe('token_beyond_acting_token')
        ->and($code(fn () => $issuer->authorizeRevocation([$theirs], $this->user)))->toBe('token_beyond_acting_token')
        ->and(cs08State())->toEqual($before);
});
