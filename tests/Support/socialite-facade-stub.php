<?php

namespace Laravel\Socialite\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * A stand-in for laravel/socialite's facade, loaded only when the real one is absent. Socialite is the HOST's install
 * (the controller's docblock says so); this package's tests only need the facade name to mock the redirect.
 */
class Socialite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'beam-accounts.test.socialite';
    }
}
