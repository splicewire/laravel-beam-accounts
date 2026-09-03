<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;

/*
 * Base-flow ACCOUNT surface — owned by splicewire/laravel-beam-accounts (the account engine).
 * The authenticated settings pages (profile, security), verified per role via the engine's demo
 * subjects: we enter as owner/admin/member/solo through the SIGNED login-as link that
 * `DemoLoginLinks::for()` mints (`users/{id}/op/login-as` — the bespoke unsigned
 * `account/login-as/{subject}` route is gone, and there is no testing-env bypass), then drive the
 * settings surface and screenshot each. The link is minted inside the test, after the browser
 * server bootstraps, so its signature carries the in-process server's origin.
 *
 * Runs inside the consuming satellite via `pest --group=browser`. The in-process browser server
 * shares the test's database connection, so the seeded demo subjects are visible to the browser.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DemoTeamSeeder::class));

dataset('roles', ['owner', 'admin', 'member', 'solo']);

it('renders the profile page for each demo subject', function (string $role) {
    visit(app(DemoLoginLinks::class)->for($role))
        ->navigate('/settings/profile')
        ->assertNoConsoleLogs()
        ->assertPathIs('/settings/profile')
        ->screenshot(filename: "account-profile-{$role}");
})->with('roles')->group('browser')
    ->skip(fn () => manifest_deviates('account.profile'), 'profile declared off');

it('renders the security page for each demo subject', function (string $role) {
    $password = config('beam.accounts.demo.password', 'password');

    // Fortify guards the security surface behind password confirmation, so the surface
    // entry redirects through /user/confirm-password before the page renders.
    visit(app(DemoLoginLinks::class)->for($role))
        ->navigate('/settings/security')
        ->assertPathIs('/user/confirm-password')
        ->fill('input[name="password"]', $password)
        ->click('[data-test="confirm-password-button"]')
        ->assertPathIs('/settings/security')
        ->assertNoConsoleLogs()
        ->screenshot(filename: "account-security-{$role}");
})->with('roles')->group('browser')
    ->skip(fn () => manifest_deviates('account.security'), 'security declared off');
