<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * ux-walkthrough UX-11 (IA-5): every sign-in door returns Landing::for(). Fortify's login, two-factor and register
 * responses are bound to it, so a host's `fortify.home` literal decides nothing (T3), and an unsafe intended URL that
 * Fortify's own redirect()->intended() would have followed is refused.
 */

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    Route::get('dashboard', fn () => 'app')->name('dashboard');
    Route::get('operator', fn () => 'operator')->name('operator.home');
    Route::getRoutes()->refreshNameLookups();
    config(['fortify.home' => '/somewhere-literal']);
    $this->operators = [];
    Gate::define('entitlement:os.operate', fn ($user) => in_array($user->getKey(), $this->operators, true));
});

function signIn($test, string $email)
{
    return $test->post('/login', ['email' => $email, 'password' => 'password-1234']);
}

it('lands a password sign-in through Landing, not fortify.home', function () {
    User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    signIn($this, 'ada@example.test')->assertRedirect('/dashboard');
});

it('lands an operator with no seat in Operator', function () {
    $op = User::create(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'password-1234']);
    $this->operators[] = $op->getKey();

    signIn($this, 'op@example.test')->assertRedirect('/operator');
});

it('keeps a guest\'s safe intended URL across sign-in', function () {
    User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $this->withSession(['url.intended' => 'http://localhost/reports/42?tab=usage']);
    signIn($this, 'ada@example.test')->assertRedirect('/reports/42?tab=usage');
});

it('refuses an intended URL smuggled past a shape check', function () {
    User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $this->withSession(['url.intended' => '/%09/evil.com']);
    signIn($this, 'ada@example.test')->assertRedirect('/dashboard');
});

it('lands a registration through Landing too', function () {
    $this->post('/register', [
        'name' => 'Bo', 'email' => 'bo@example.test', 'password' => 'password-1234', 'password_confirmation' => 'password-1234',
    ])->assertRedirect('/dashboard');
});

it('answers a JSON sign-in the way Fortify does', function () {
    User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $this->postJson('/login', ['email' => 'ada@example.test', 'password' => 'password-1234'])
        ->assertOk()->assertJson(['two_factor' => false]);
});

it('lands a passkey sign-in through Landing as well', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);
    $request = Illuminate\Http\Request::create('/passkeys/login', 'POST');
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);
    $request->session()->put('url.intended', '/%0a/evil.com');

    $response = app(Laravel\Passkeys\Contracts\PasskeyLoginResponse::class)->toResponse($request);

    expect($response->getTargetUrl())->toBe(url('/dashboard'));
});

it('lands Fortify\'s redirect-as-intended (the verification prompt and resend) through Landing', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);
    $request = Illuminate\Http\Request::create('/email/verify');
    $request->setLaravelSession($this->app['session.store']);
    $request->setUserResolver(fn () => $user);

    // Fortify's controllers build it exactly so: by class, with a name.
    $response = app(Laravel\Fortify\Http\Responses\RedirectAsIntended::class, ['name' => 'email-verification'])->toResponse($request);

    expect($response->getTargetUrl())->toBe(url('/dashboard'));
});
