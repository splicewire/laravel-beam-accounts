<?php

use function Splicewire\Beam\Testing\flow;

/*
 * Base-flow AUTH surface — owned by splicewire/laravel-beam-accounts (the account engine).
 * These tests ship with the package and run inside the consuming satellite via
 * `pest --group=browser`. Fortify owns the routes; here we verify each entry page renders and
 * capture a screenshot.
 *
 * The unauthenticated entry pages need no seeded database — they prove the surface is present
 * and rendering. Authenticated account pages are covered per-role in ../account.
 *
 * browser-journeys 10: each test names its flow through `flow()` (skip on a covering Deviation with its
 * reason, record a FlowResultData row, name the screenshot). These are guest flows (no role). 10 adds
 * `auth.login` to AccountFlows so login names a flow too (the gap 08's deviation-focused logic left).
 */

it('renders the login page', function () {
    $flow = flow('auth.login');

    visit('/login')
        ->assertNoConsoleLogs()
        ->screenshot(filename: $flow->name());
})->group('browser');

it('renders the registration page', function () {
    $flow = flow('auth.register');

    visit('/register')
        ->assertNoConsoleLogs()
        ->screenshot(filename: $flow->name());
})->group('browser');

it('renders the forgot-password page', function () {
    $flow = flow('auth.forgot-password');

    visit('/forgot-password')
        ->assertNoConsoleLogs()
        ->screenshot(filename: $flow->name());
})->group('browser');
