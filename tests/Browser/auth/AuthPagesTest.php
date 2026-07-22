<?php

/*
 * Base-flow AUTH surface — owned by splicewire/laravel-beam-accounts (the account engine).
 * These tests ship with the package and run inside the consuming satellite via
 * `pest --group=browser`. Fortify owns the routes; here we verify each entry page renders and
 * capture a screenshot at the convention path (tests/Browser/Screenshots/<group>/<flow>.png).
 *
 * The unauthenticated entry pages need no seeded database — they prove the surface is present
 * and rendering. Authenticated account pages are covered per-role in ../account.
 */

it('renders the login page', function () {
    visit('/login')
        ->assertNoConsoleLogs()
        ->screenshot(filename: 'auth-login');
})->group('browser');

it('renders the registration page', function () {
    visit('/register')
        ->assertNoConsoleLogs()
        ->screenshot(filename: 'auth-register');
})->group('browser')->skip(fn () => manifest_deviates('auth.register'), 'registration declared off');

it('renders the forgot-password page', function () {
    visit('/forgot-password')
        ->assertNoConsoleLogs()
        ->screenshot(filename: 'auth-forgot-password');
})->group('browser')->skip(fn () => manifest_deviates('auth.forgot-password'), 'password reset declared off');
