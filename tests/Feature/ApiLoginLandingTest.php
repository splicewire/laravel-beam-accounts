<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\HasApiTokens;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/** The suite's fixture user, able to hold personal access tokens as a host's user model does. */
class LandingTokenUser extends User
{
    use HasApiTokens;

    protected $table = 'users';

    /** Relations key on `user_id`, as on a host's `User`, not on this subclass's class name. */
    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

/*
 * ux-walkthrough UX-11 (IA-5): the SPA's sign-in answers where to land, from the same resolver every other door uses,
 * so the SPA stops guessing a literal (the flagship's '/tenants'). It rides the envelope's meta: `meta.landing`.
 */

beforeEach(function () {
    config(['auth.providers.users.model' => LandingTokenUser::class]);
    // The package's test app loads no Sanctum provider; a host's does (as ApiLogoutTest).
    $this->app->register(Laravel\Sanctum\SanctumServiceProvider::class);
    $this->createPersonalAccessTokensSchema();
    Route::get('dashboard', fn () => 'app')->name('dashboard');
    Route::prefix('api/v1')->name('api.v1.')->middleware(['api'])->group(fn () => Route::splicewireAccountsAuthV1());
    Route::getRoutes()->refreshNameLookups();
});

it('answers a password sign-in with its landing', function () {
    LandingTokenUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $this->postJson('/api/v1/login', ['email' => 'ada@example.test', 'password' => 'password-1234'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.test')
        ->assertJsonPath('meta.landing', '/dashboard');
});

it('claims no landing for a refused sign-in', function () {
    $this->postJson('/api/v1/login', ['email' => 'nobody@example.test', 'password' => 'nope'])
        ->assertUnauthorized()
        ->assertJsonMissingPath('meta.landing');
});
