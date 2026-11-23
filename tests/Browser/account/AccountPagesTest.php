<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Flows\AccountFlows;

use function Splicewire\Beam\Testing\flow;

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
 *
 * browser-journeys 10: each test names its flow through `flow()` — which skips on a covering BEAM.md
 * Deviation with its reason, records a FlowResultData row, and names the screenshot — and takes its role
 * rows from `AccountFlows`. This file uses RefreshDatabase: flow() requires the host test case to
 * carry one of Laravel's four reset traits (FlowRun::assertResetTrait), and no host supplies one.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DemoTeamSeeder::class));

it('renders the profile page for each demo subject', function (string $role) {
    $flow = flow('account.profile', role: $role);

    visit(app(DemoLoginLinks::class)->for($role))
        ->navigate('/settings/profile')
        ->assertNoConsoleLogs()
        ->assertPathIs('/settings/profile')
        ->screenshot(filename: $flow->name());
})->with(AccountFlows::flow('account.profile')->roles)->group('browser');

it('renders the security page for each demo subject', function (string $role) {
    $flow = flow('account.security', role: $role);

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
        ->screenshot(filename: $flow->name());
})->with(AccountFlows::flow('account.security')->roles)->group('browser');
