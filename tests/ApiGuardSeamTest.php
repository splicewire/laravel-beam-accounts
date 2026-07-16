<?php

use Schemastud\Beam\Accounts\BeamAccountsServiceProvider;

it('does not register the api guard by default', function () {
    expect(config('splicewire.account.api.enabled'))->toBeFalse();
    expect(config('auth.guards.api'))->toBeNull();
});

it('leaves the web/session guard intact', function () {
    expect(config('fortify.guard'))->toBe('web');
    expect(config('auth.guards.web.driver'))->toBe('session');
});

it('wires a sanctum api guard only when the seam is enabled', function () {
    // Re-run the seam with the flag on, simulating a host that opted in.
    config(['splicewire.account.api.enabled' => true]);

    $provider = new BeamAccountsServiceProvider($this->app);
    $reflect = new ReflectionMethod($provider, 'bootApiGuardSeam');
    $reflect->setAccessible(true);
    $reflect->invoke($provider);

    expect(config('auth.guards.api.driver'))->toBe('sanctum');
    expect(config('auth.guards.api.provider'))->toBe('users');
    // The session door is still untouched.
    expect(config('auth.guards.web.driver'))->toBe('session');
});
