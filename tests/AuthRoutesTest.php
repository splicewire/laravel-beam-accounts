<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Schemastud\Beam\Accounts\Enums\Role;
use Schemastud\Beam\Accounts\Tests\Fixtures\User;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

it('registers a user through Fortify and provisions their team-of-one', function () {
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
    expect($user->personalTeam())->not->toBeNull();
    expect($user->memberships()->value('role'))->toBe(Role::Owner->value);
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
