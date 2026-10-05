<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\LogoutController;
use Laravel\Sanctum\HasApiTokens;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * The packaged logout (tower-is-splicewire D3′, INTERSECTION §5): Sign out must end the sign-in on the SERVER — revoke the
 * token the client signed in with and invalidate the session — not just clear client state. Mounted here the way a host
 * mounts its api/v1 routes: under auth:sanctum, which accepts the web session first and then a bearer.
 */

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    // The package's test app loads no Sanctum provider; a host's does. Register it so `auth:sanctum` exists here too.
    $this->app->register(\Laravel\Sanctum\SanctumServiceProvider::class);
    $this->createPersonalAccessTokensSchema();

    Route::middleware('auth:sanctum')->post('/api/v1/logout', [LogoutController::class, 'logout']);
    Route::middleware('auth:sanctum')->get('/api/v1/whoami', fn (Request $request) => ['id' => (string) $request->user()->getKey()]);
    Route::middleware(['web', 'auth:sanctum'])->post('/session/logout', [LogoutController::class, 'logout']);
    Route::getRoutes()->refreshNameLookups();
});

/** The suite's fixture user, able to hold personal access tokens as a host's user model does. */
class LogoutTokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';
}

function logoutUser(): LogoutTokenUser
{
    return LogoutTokenUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'x']);
}

function sessionToken(LogoutTokenUser $user): string
{
    return AuthTokenFactory::mint($user, 'Probe/1.0', TokenProvenance::Session, false)->plainTextToken;
}

it('revokes the token it was called with, so reusing it is refused', function () {
    $token = sessionToken(logoutUser());
    $this->withToken($token)->getJson('/api/v1/whoami')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/whoami')->assertUnauthorized();
});

it('revokes only that token, never the user\'s other tokens', function () {
    $user = logoutUser();
    $signedIn = sessionToken($user);
    $other = sessionToken($user);

    $this->withToken($signedIn)->postJson('/api/v1/logout')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($other)->getJson('/api/v1/whoami')->assertOk();
});

it('invalidates the session when the caller is signed in by session', function () {
    $this->actingAs(logoutUser(), 'web')->withSession(['probe' => 'kept until logout'])
        ->postJson('/session/logout')->assertOk();

    $this->assertGuest('web');
    expect(session()->has('probe'))->toBeFalse();
});

it('is safe with an already-revoked token: a clean 401, not an error', function () {
    $token = sessionToken(logoutUser());
    $this->withToken($token)->postJson('/api/v1/logout')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/logout')->assertUnauthorized();
});
