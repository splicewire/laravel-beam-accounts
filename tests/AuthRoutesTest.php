<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('registers a user through Fortify, door-checked, with no team at registration', function () {
    $response = $this->post('/register', [
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated();

    $user = User::firstWhere('email', 'ada@example.test');
    expect($user)->not->toBeNull();
    // purchase-walkthrough M10, lead ruling 2026-10-05: registration provisions no team (a host may, in afterCreating).
    expect($user->personalTeam())->toBeNull();
    expect($user->memberships()->count())->toBe(0);
});

it('logs an existing user in through Fortify', function () {
    User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $response = $this->post('/login', [
        'email' => 'ada@example.test',
        'password' => 'password-1234',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated();
});

it('exposes the password-reset and email-verification action routes', function () {
    // View routes are host-supplied (issue 06); the POST action routes are what
    // Fortify wires regardless, proving reset + verify are enabled.
    expect(Route::has('password.email'))->toBeTrue();
    expect(Route::has('password.update'))->toBeTrue();
    expect(Route::has('verification.send'))->toBeTrue();
});
