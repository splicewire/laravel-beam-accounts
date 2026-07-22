<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;

/*
 * Base-flow ACCOUNT surface — owned by splicewire/laravel-beam-accounts (the account engine).
 * The authenticated settings pages (profile, security), verified per role via the engine's demo
 * subjects: we enter as owner/admin/member/solo through the `account/login-as/{subject}` link
 * (unsigned in the testing env), then drive the settings surface and screenshot each.
 *
 * Runs inside the consuming satellite via `pest --group=browser`. The in-process browser server
 * shares the test's database connection, so the seeded demo subjects are visible to the browser.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DemoTeamSeeder::class));

dataset('roles', ['owner', 'admin', 'member', 'solo']);

it('renders the profile page for each demo subject', function (string $role) {
    visit("/account/login-as/{$role}")
        ->navigate('/settings/profile')
        ->assertNoConsoleLogs()
        ->assertPathIs('/settings/profile')
        ->screenshot(filename: "account-profile-{$role}");
})->with('roles')->group('browser')
    ->skip(fn () => manifest_deviates('account.profile'), 'profile declared off');

it('renders the security page for each demo subject', function (string $role) {
    $password = config('splicewire.account.demo.password', 'password');

    // Fortify guards the security surface behind password confirmation, so the surface
    // entry redirects through /user/confirm-password before the page renders.
    visit("/account/login-as/{$role}")
        ->navigate('/settings/security')
        ->assertPathIs('/user/confirm-password')
        ->fill('input[name="password"]', $password)
        ->click('[data-test="confirm-password-button"]')
        ->assertPathIs('/settings/security')
        ->assertNoConsoleLogs()
        ->screenshot(filename: "account-security-{$role}");
})->with('roles')->group('browser')
    ->skip(fn () => manifest_deviates('account.security'), 'security declared off');
