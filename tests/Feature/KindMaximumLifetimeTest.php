<?php

use Carbon\CarbonInterval;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
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
use Splicewire\Beam\Accounts\Tokens\MachineTokenIssuer;
use Splicewire\Beam\Accounts\Tokens\SamePrincipalTokenKind;
use Splicewire\Beam\Accounts\Tokens\TokenIssuance;
use Splicewire\Beam\Accounts\Tokens\TokenKind;
use Splicewire\Beam\Localization\CodedValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * credential-scope 09 (l145 review F5): a token kind may declare a maximum lifetime, and the issuer refuses a mint, a
 * renewal or a rotation of that kind with no expiry or one past now plus the lifetime, whoever asks. A zero or
 * negative lifetime is a misconfiguration and refuses everything; a kind that declares none is unchanged.
 *
 * Mints through the issuer; renewal and rotation also through the real token-management routes a host mounts, with a
 * real Sanctum login token (`*`, so the token-management names are not what is under test) and enforcement live the
 * way a host booted with it on binds it.
 */

beforeEach(function () {
    $this->app->register(Laravel\Sanctum\SanctumServiceProvider::class);
    $this->createPersonalAccessTokensSchema();
    Sanctum::$accessTokenAuthenticationCallback = null;
    config([
        'beam.accounts.api.scope_enforcement' => true,
        'beam.accounts.tokens.kinds.cs09-capped' => Cs09CappedKind::class,
        'beam.accounts.tokens.kinds.cs09-uncapped' => Cs09UncappedKind::class,
        'cs09.capped_days' => 30,
    ]);
    $provider = new BeamAccountsServiceProvider(app());
    $boot = new ReflectionMethod($provider, 'bootApiGuardEnforcement');
    $boot->setAccessible(true);
    $boot->invoke($provider);
    app()->forgetInstance(CredentialScope::class);

    Gate::define('entity.view', fn ($user = null) => $user !== null);

    Route::prefix('api/v1')->middleware(['auth:sanctum'])->group(
        fn () => Route::splicewireAccountApiRoutes(prefix: 'beam/accounts', middleware: [])
    );
    Route::getRoutes()->refreshNameLookups();

    $this->user = Cs09LifetimeUser::create(['name' => 'Ada', 'email' => 'ada-cs09@example.test', 'password' => 'x']);
    $this->issuer = app(MachineTokenIssuer::class);
});

afterEach(function () {
    Sanctum::$accessTokenAuthenticationCallback = null;
    config(['beam.accounts.api.scope_enforcement' => false]);
});

class Cs09LifetimeUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}

/** A same-principal kind capped at `cs09.capped_days` days (a host value, read through the fail-closed helper). */
class Cs09CappedKind extends SamePrincipalTokenKind
{
    public function abilities(Authenticatable $principal, ?array $requested): array
    {
        return ['entity.view'];
    }

    public function maxLifetime(): CarbonInterval
    {
        return TokenKind::lifetimeInDays(config('cs09.capped_days'));
    }
}

/** A same-principal kind that declares no lifetime: unchanged by this rule. */
class Cs09UncappedKind extends SamePrincipalTokenKind
{
    public function abilities(Authenticatable $principal, ?array $requested): array
    {
        return ['entity.view'];
    }
}

/** The refusal's stable code: a coded field error on `expiresInDays`/`abilities`, or a coded HTTP refusal. */
function cs09LifetimeRefusal(Closure $act): string
{
    try {
        $act();
    } catch (CodedValidationException $e) {
        $codes = $e->codes();

        return $codes['expiresInDays'][0] ?? $codes['abilities'][0] ?? 'uncoded-validation';
    } catch (HttpException $e) {
        return $e->error->code();
    }

    throw new RuntimeException('expected a refusal, but it went through');
}

function cs09Mint(Authenticatable $actor, string $kind, ?DateTimeInterface $expiresAt)
{
    return app(MachineTokenIssuer::class)->issue($kind, new TokenIssuance(actor: $actor, name: 'cs09', expiresAt: $expiresAt === null ? null : Carbon\Carbon::instance($expiresAt)));
}

/** A real login token (`*`), the unscoped caller a signed-in user's client is. */
function cs09Login(Cs09LifetimeUser $user): string
{
    $new = AuthTokenFactory::mint($user, 'Browser/1.0', TokenProvenance::Session, false);
    $new->accessToken->forceFill(['provenance' => TokenProvenance::Session->value])->save();

    return $new->plainTextToken;
}

function cs09As(string $bearer)
{
    app('auth')->forgetGuards();

    return test()->withToken($bearer);
}

function cs09Rows(): array
{
    return PersonalAccessToken::query()->orderBy('id')->get()
        ->map(fn (PersonalAccessToken $t) => [$t->id, $t->expires_at?->toIso8601String(), $t->token, $t->getAttribute('archived_at')])->all();
}

it('binds the bearer scope resolver, so enforcement is live as on a booted host', function () {
    expect(app(CredentialScopeResolver::class))->toBeInstanceOf(TokenAbilitiesScopeResolver::class);
});

it('refuses a mint of a capped kind with no expiry, or one past its lifetime, and writes nothing', function () {
    $before = cs09Rows();

    expect(cs09LifetimeRefusal(fn () => cs09Mint($this->user, 'cs09-capped', null)))->toBe('token_lifetime_beyond_kind')
        ->and(cs09LifetimeRefusal(fn () => cs09Mint($this->user, 'cs09-capped', now()->addDays(30)->addMinute())))->toBe('token_lifetime_beyond_kind')
        ->and(cs09Rows())->toEqual($before);
});

it('mints a capped kind up to its lifetime', function () {
    $issued = cs09Mint($this->user, 'cs09-capped', $at = now()->addDays(30));

    expect($issued->token->fresh()->expires_at->toIso8601String())->toBe($at->toIso8601String())
        ->and(cs09Mint($this->user, 'cs09-capped', now()->addDay())->token->kind)->toBe('cs09-capped');
});

it('leaves a kind that declares no lifetime unchanged: it may still never expire', function () {
    expect(cs09Mint($this->user, 'cs09-uncapped', null)->token->fresh()->expires_at)->toBeNull()
        ->and(cs09Mint($this->user, 'personal-access-token', null)->token->fresh()->expires_at)->toBeNull();
});

it('refuses every issuance of a kind whose host lifetime is not a positive whole number', function (mixed $days) {
    config(['cs09.capped_days' => $days]);

    expect(cs09LifetimeRefusal(fn () => cs09Mint($this->user, 'cs09-capped', now()->addDay())))->toBe('token_kind_lifetime_invalid')
        ->and(PersonalAccessToken::query()->count())->toBe(0);
})->with([
    'zero' => [0],
    'negative' => [-5],
    'unset' => [null],
    'not a number' => ['90d'],
    'a fraction' => [1.5],
]);

it('reads a numeric string from the environment as whole days', function () {
    config(['cs09.capped_days' => '7']);

    expect(cs09LifetimeRefusal(fn () => cs09Mint($this->user, 'cs09-capped', now()->addDays(8))))->toBe('token_lifetime_beyond_kind')
        ->and(cs09Mint($this->user, 'cs09-capped', now()->addDays(7))->token->kind)->toBe('cs09-capped');
});

it('refuses renewing a capped token to never, or past its lifetime, through the real route for a signed-in user, writing nothing', function () {
    $token = cs09Mint($this->user, 'cs09-capped', now()->addDay())->token;
    $login = cs09Login($this->user);
    $before = cs09Rows();

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$token->getKey()}/renew", [])
        ->assertStatus(422)
        ->assertJsonPath('codes.expiresInDays.0', 'token_lifetime_beyond_kind');

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$token->getKey()}/renew", ['expiresInDays' => 31])
        ->assertStatus(422)
        ->assertJsonPath('codes.expiresInDays.0', 'token_lifetime_beyond_kind');

    expect(cs09Rows())->toEqual($before);

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$token->getKey()}/renew", ['expiresInDays' => 30])->assertOk();

    expect($token->fresh()->expires_at->greaterThan(now()->addDays(29)))->toBeTrue();
});

it('refuses rotating a capped token to never, or past its lifetime, through the real route, and archives nothing', function () {
    $token = cs09Mint($this->user, 'cs09-capped', now()->addDay())->token;
    $login = cs09Login($this->user);
    $before = cs09Rows();

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$token->getKey()}/rotate", [])
        ->assertStatus(422)
        ->assertJsonPath('codes.expiresInDays.0', 'token_lifetime_beyond_kind');

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$token->getKey()}/rotate", ['expiresInDays' => 45])
        ->assertStatus(422)
        ->assertJsonPath('codes.expiresInDays.0', 'token_lifetime_beyond_kind');

    expect(cs09Rows())->toEqual($before);

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$token->getKey()}/rotate", ['expiresInDays' => 10])->assertCreated();
});

it('renews a token of an uncapped kind, or of no recorded kind, to never, as before', function () {
    $uncapped = cs09Mint($this->user, 'cs09-uncapped', now()->addDay())->token;
    [, $legacy] = [null, $this->user->createToken('legacy', ['entity.view'], now()->addDay())->accessToken]; // a row from before kinds
    $login = cs09Login($this->user);

    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$uncapped->getKey()}/renew", [])->assertOk();
    cs09As($login)->postJson("/api/v1/beam/accounts/tokens/{$legacy->getKey()}/renew", [])->assertOk();

    expect($uncapped->fresh()->expires_at)->toBeNull()->and($legacy->fresh()->expires_at)->toBeNull();
});

it('refuses renewing a row whose recorded kind is no longer declared, rather than reading it as uncapped', function () {
    $token = cs09Mint($this->user, 'cs09-capped', now()->addDay())->token;
    config(['beam.accounts.tokens.kinds.cs09-capped' => null]);
    $before = cs09Rows();

    expect(cs09LifetimeRefusal(fn () => app(MachineTokenIssuer::class)->renew($token, new TokenIssuance(actor: $this->user, name: 'cs09', expiresAt: null))))
        ->toBe('token_kind_undeclared')
        ->and(cs09Rows())->toEqual($before);
});
