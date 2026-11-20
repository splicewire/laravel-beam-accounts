<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Authorization\TokenAbilitiesScopeResolver;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * Fleet ADR 0006 C5, the request side (credential-scope 02): enforcement off no longer means "unscoped". With
 * `beam.accounts.api.scope_enforcement` off, a bearer whose token carries an ability list other than `*` authenticates
 * nobody, so it gains nothing; a login (`*`) token and a session are unchanged. With enforcement on, the same token
 * authenticates and its scope narrows every Gate decision. The default stays off in this package (ticket 06 flips it).
 */

beforeEach(function () {
    $this->app->register(Laravel\Sanctum\SanctumServiceProvider::class);
    $this->createPersonalAccessTokensSchema();
    Sanctum::$accessTokenAuthenticationCallback = null;
    bootEnforcement();

    Gate::define('entity.view', fn ($user = null) => $user !== null);
    Gate::define('entity.delete', fn ($user = null) => $user !== null);
    Route::middleware('auth:sanctum')->get('/api/v1/cs02/whoami', fn (Request $request) => [
        'id' => (string) $request->user()->getKey(),
        'view' => Gate::allows('entity.view'),
        'delete' => Gate::allows('entity.delete'),
    ]);
});

afterEach(function () {
    Sanctum::$accessTokenAuthenticationCallback = null;
    config(['beam.accounts.api.scope_enforcement' => false]);
});

class EnforcementTokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}

/** Run the provider's enforcement link again, as a host booting with the current config would. */
function bootEnforcement(): void
{
    $provider = new BeamAccountsServiceProvider(app());
    $method = new ReflectionMethod($provider, 'bootApiGuardEnforcement');
    $method->setAccessible(true);
    $method->invoke($provider);
}

function enforcementUser(): EnforcementTokenUser
{
    return EnforcementTokenUser::create(['name' => 'Ada', 'email' => 'ada-cs02@example.test', 'password' => 'x']);
}

it('defaults scope enforcement off', function () {
    expect(config('beam.accounts.api.scope_enforcement'))->toBeFalse();
});

it('refuses a scoped token while enforcement is off: it authenticates nobody and gains nothing', function () {
    $user = enforcementUser();
    $scoped = $user->createToken('restricted', ['entity.view'])->plainTextToken;

    $this->withToken($scoped)->getJson('/api/v1/cs02/whoami')->assertUnauthorized();
    expect(app()->bound(CredentialScopeResolver::class) && app(CredentialScopeResolver::class) instanceof TokenAbilitiesScopeResolver)->toBeFalse();
});

it('refuses a scoped row restored or imported while enforcement is off', function () {
    $user = enforcementUser();
    $token = $user->createToken('imported', ['*']);
    PersonalAccessToken::query()->whereKey($token->accessToken->getKey())->update(['abilities' => json_encode(['entity.view'])]);

    $this->withToken($token->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertUnauthorized();
});

it('leaves a login (star) token unchanged while enforcement is off', function () {
    $user = enforcementUser();
    $login = AuthTokenFactory::mint($user, 'Probe/1.0', TokenProvenance::Session, false)->plainTextToken;

    $this->withToken($login)->getJson('/api/v1/cs02/whoami')->assertOk()->assertJson(['view' => true, 'delete' => true]);
});

it('authenticates a scoped token and narrows it once enforcement is on', function () {
    config(['beam.accounts.api.scope_enforcement' => true]);
    bootEnforcement();
    $user = enforcementUser();
    $scoped = $user->createToken('restricted', ['entity.view'])->plainTextToken;

    $this->withToken($scoped)->getJson('/api/v1/cs02/whoami')->assertOk()->assertJson(['view' => true, 'delete' => false]);
});

it('still runs a validation callback the host installed first', function () {
    Sanctum::$accessTokenAuthenticationCallback = null;
    Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => $isValid && $token->name !== 'blocked');
    bootEnforcement();
    $user = enforcementUser();

    $this->withToken($user->createToken('blocked', ['*'])->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertUnauthorized();
    $this->withToken($user->createToken('scoped', ['entity.view'])->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertUnauthorized();
    $this->withToken($user->createToken('fine', ['*'])->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertOk();
});

it('refuses a scoped or empty-ability token even when a preceding callback turns an invalid token valid', function () {
    // codex cs02 (blocking 2): the host callback runs after the veto, so it cannot readmit a scoped token by answering
    // true for one Sanctum judged invalid (here: expired).
    Sanctum::$accessTokenAuthenticationCallback = null;
    Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => true);
    bootEnforcement();
    $user = enforcementUser();

    foreach (['scoped' => ['entity.view'], 'empty' => []] as $name => $abilities) {
        $token = $user->createToken($name, $abilities, now()->subMinute());
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertUnauthorized();
    }

    // The same callback still decides for a `*` token: it admits an expired one, as the host wrote it.
    app('auth')->forgetGuards();
    $this->withToken($user->createToken('star', ['*'], now()->subMinute())->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertOk();
});

it('composes a callback a host installs after the package booted under the veto, once the app has booted', function () {
    // An application still booting: the package's link runs, then a host's own boot replaces Sanctum's single callback
    // slot, then the app finishes booting and the booted hook installs the veto over it again.
    $booting = new Illuminate\Foundation\Application(base_path());
    // The constructor makes itself the global container; the request below runs on the test app.
    Illuminate\Container\Container::setInstance($this->app);
    Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
    $provider = new BeamAccountsServiceProvider($booting);
    Sanctum::$accessTokenAuthenticationCallback = null;
    $method = new ReflectionMethod($provider, 'bootApiGuardEnforcement');
    $method->setAccessible(true);
    $method->invoke($provider);
    Sanctum::authenticateAccessTokensUsing(fn ($token, bool $isValid) => true);
    $booting->boot();

    $user = enforcementUser();
    $this->withToken($user->createToken('late', ['entity.view'])->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($user->createToken('late-star', ['*'], now()->subMinute())->plainTextToken)->getJson('/api/v1/cs02/whoami')->assertOk();
});
