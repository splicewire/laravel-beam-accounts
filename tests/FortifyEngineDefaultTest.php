<?php

use Illuminate\Cache\RateLimiter;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Schemastud\Beam\Accounts\Fortify\CreateNewUser;
use Schemastud\Beam\Accounts\Fortify\ResetUserPassword;

/**
 * The engine's load-bearing default: Fortify (session auth) is wired as the account
 * substrate by the service provider — the engine's registration + password-reset
 * actions are bound to Fortify's contracts, and the rate limiters exist. Session,
 * never Passport.
 */
it('wires the engine Fortify actions as the Fortify contract defaults', function () {
    expect(app(CreatesNewUsers::class))->toBeInstanceOf(CreateNewUser::class);
    expect(app(ResetsUserPasswords::class))->toBeInstanceOf(ResetUserPassword::class);
});

it('registers the login and two-factor rate limiters Fortify depends on', function () {
    $limiter = app(RateLimiter::class);

    expect($limiter->limiter('login'))->not->toBeNull();
    expect($limiter->limiter('two-factor'))->not->toBeNull();
});

it('runs the account engine on the session web guard, not Passport', function () {
    expect(config('fortify.guard'))->toBe('web');
    expect(config('auth.guards.web.driver'))->toBe('session');
    expect(class_exists('Laravel\Passport\Passport'))->toBeFalse();
});
