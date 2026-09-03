<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\Team;

/*
 * Base-flow TEAMS surface — owned by splicewire/laravel-beam-accounts (the account engine), per the
 * runbook's ADR-0002 (accounts → auth/account/teams) and references/teams.md.
 *
 * ## What this suite covers, and why it is ONE flow rather than five
 *
 * teams.md lists five base flows: team-of-one on registration, invite, change role, remove, and
 * leave/transfer. Exactly one of them has a BROWSER surface this package ships:
 *
 *  - **team-of-one on registration** — Fortify's `/register` runs the engine's `CreateNewUser`,
 *    which hands the fresh account its personal team through `TeamProvisioner`. Verified end to
 *    end here: the host's registration form is driven in a real browser, then the personal-team row
 *    is asserted on the connection the in-process server shares with this test.
 *
 * The other four are `TeamMembers` service calls with no route in `routes/account.php` (which mounts
 * profile + security only). They are covered where they live — tests/MultiMemberTeamsTest.php walks
 * invite → accept → change-role → remove, and tests/InvitationAbilityTest.php the gates. Writing
 * browser tests for flows this package does not mount would mean inventing host pages, which
 * ADR-0002 assigns to the host, not to the capability.
 *
 * ⚠️ The `members` roster (the `members` Frame resource this package registers in
 * `WiresFrameResources`) is deliberately NOT asserted through the Frame socket here. It is
 * SOURCE-backed — `MembershipSource`, no `model:` — and a host binding its own
 * `FrameResourceHandlerResolver` that assumes a model-backed definition 500s on it. That is exactly
 * what `~/Herd/numero` does today (`App\Frame\NumeroResourceHandler::index` reads
 * `$definition->model` and calls `$modelClass::query()`), measured 2026-09-03. Asserting it here
 * would make a HOST wiring fact fail a PACKAGE suite — the estate's "a check whose answer depends
 * on the host must not throw" rule, one tier up.
 *
 * Runs inside the consuming satellite via `pest --group=browser`. The in-process browser server
 * shares the test's database connection, so seeded rows are visible to the browser and rows the
 * browser writes are visible here.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DemoTeamSeeder::class));

it('provisions a team-of-one on registration', function () {
    $email = 'teams-registrant@example.test';

    visit('/register')
        ->fill('input[name="name"]', 'Team Of One')
        ->fill('input[name="email"]', $email)
        ->fill('input[name="password"]', 'password-1234')
        ->fill('input[name="password_confirmation"]', 'password-1234')
        ->click('[data-test="register-user-button"]')
        ->assertPathIsNot('/register')
        ->assertNoConsoleLogs()
        ->screenshot(filename: 'teams-team-of-one');

    $user = BeamAccounts::userModel()::query()->where('email', $email)->first();

    expect($user)->not->toBeNull();
    expect(
        Team::query()->where('user_id', $user->getKey())->where('personal_team', true)->exists()
    )->toBeTrue();
})->group('browser')
    ->skip(
        fn () => manifest_deviates('auth.register') || manifest_deviates('teams.team-of-one'),
        'registration or team-of-one declared off',
    );
