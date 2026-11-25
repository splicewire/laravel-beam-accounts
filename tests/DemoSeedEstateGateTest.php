<?php

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Actions\DemoLoginLinks;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The demo-seed gate reads the TEAMS estate's third state, not its publish flag.
 *
 * `publish_migrations` has three values and only one of them declines the estate
 * ({@see BeamAccountsServiceProvider::estateDeclaredAbsent()}): `true` publishes the stubs,
 * `false` claims *"every member is already committed on my disk"*, and `'absent'` says the estate
 * has no place at this host. ANDing the demo gate with `publishesEstateNamed('migrations')` collapsed
 * the first two: a host that committed the migrations itself and turned publishing off read as a host
 * that had declined the estate, so `DemoTeamSeeder` never ran and `beam_teams` stayed empty on a
 * freshly installed host that had just migrated those very tables.
 *
 * Measured at `~/Workspaces/tmp/fresh/tower-20260911-212403` (ux-demo-convergence
 * `G1-TOWER-FRESH-INSTALL`, 2026-09-12): `laravel-tower-starter` sets `publish_migrations => false`
 * because it carries the teams migrations in its own repository; `ACCOUNT_SEED_DEMO_USERS=true`;
 * `splicewire:beam:seed` skipped the demo step, and `demo-owner` got 403 on `/operator` for want of
 * a team and a realm grant. Forcing the gate and seeding by hand turned the journey green.
 *
 * The two halves of the honest gate are tested here and they live in two different places on purpose:
 * the config gate states POLICY (are demo subjects wanted, and has this host declined the estate),
 * which must stay a pure config read because it is resolved in the provider's `boot` — no database
 * exists to ask at that point, and asking would put a schema query on every request. Whether the
 * tables are actually THERE is capability, and the seeder answers it at run time, exactly as its
 * ungated sibling {@see \Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder} already does.
 */
function rebootAccountsProvider(): void
{
    app()->register(new BeamAccountsServiceProvider(app()), force: true);
}

it('treats publish_migrations => false as "committed here", not as a declined estate', function () {
    config([
        'beam.accounts.publish_migrations' => false,
        'beam.accounts.demo.seed_users' => true,
    ]);

    rebootAccountsProvider();

    expect(BeamAccountsServiceProvider::estateDeclaredPresent('migrations'))->toBeTrue()
        ->and(config('beam.accounts.demo.seed_users'))->toBeTrue();
});

it('reads the same claim through the deprecated register_* spelling', function () {
    config([
        'beam.accounts.register_migrations' => false,
        'beam.accounts.demo.seed_users' => true,
    ]);

    rebootAccountsProvider();

    expect(BeamAccountsServiceProvider::estateDeclaredPresent('migrations'))->toBeTrue()
        ->and(config('beam.accounts.demo.seed_users'))->toBeTrue();
});

/**
 * A teams-absent host is NOT seeded by DEFAULT — it runs its own team system over its own user table
 * and `null` (the key's default) defers to that estate, so existing such hosts are never surprised
 * into fabricating `beam_teams`. The outage the old AND was added for stays fixed: the fatal lived in
 * the SEEDER running against a missing table, and {@see DemoTeamSeeder::run()} now seeds the
 * team-agnostic subjects BEFORE its teams-table guard and never creates `beam_teams` on such a host.
 */
it('leaves an absent-estate host off by default (null seed_users defers to the absent estate)', function () {
    config([
        'beam.accounts.publish_migrations' => 'absent',
        'beam.accounts.demo.seed_users' => null,
    ]);

    rebootAccountsProvider();

    expect(BeamAccountsServiceProvider::estateDeclaredPresent('migrations'))->toBeFalse()
        ->and(config('beam.accounts.demo.seed_users'))->toBeFalse();
});

/**
 * ...but an EXPLICIT `true` opts it in (ticket 29): the flagship runs its own team system (teams
 * estate absent) yet must seed login-able demo SUBJECTS for its accounts browser flows. The subjects
 * are team-agnostic and seed before the seeder's teams-table guard, so the opt-in is safe — the step
 * runs and `beam_teams` is still never created. The host's explicit decision wins over the estate.
 */
it('lets an absent-estate host opt IN to demo subjects with an explicit true', function () {
    config([
        'beam.accounts.publish_migrations' => 'absent',
        'beam.accounts.demo.seed_users' => true,
    ]);

    rebootAccountsProvider();

    expect(BeamAccountsServiceProvider::estateDeclaredPresent('migrations'))->toBeFalse()
        ->and(config('beam.accounts.demo.seed_users'))->toBeTrue();
});

it('reads the explicit opt-in through the deprecated register_* spelling too', function () {
    config([
        'beam.accounts.register_migrations' => 'absent',
        'beam.accounts.demo.seed_users' => true,
    ]);

    rebootAccountsProvider();

    expect(config('beam.accounts.demo.seed_users'))->toBeTrue();
});

/** An explicit `false` from the host still wins; the estate reading only ever narrows it. */
it('keeps an explicitly disabled demo gate off even where the estate is present', function () {
    config([
        'beam.accounts.publish_migrations' => true,
        'beam.accounts.demo.seed_users' => false,
    ]);

    rebootAccountsProvider();

    expect(config('beam.accounts.demo.seed_users'))->toBeFalse();
});

/**
 * Capability, not policy: with the gate open but the tables missing, the seeder must SAY SO and
 * return rather than fatal on `relation "beam_teams" does not exist` half-way through a seed run.
 */
it('skips the demo seeder, without fataling, when the teams tables are not there', function () {
    Schema::dropIfExists((new Team)->getTable());

    app(DemoTeamSeeder::class)->run();

    expect(Schema::hasTable((new Team)->getTable()))->toBeFalse();
});

/**
 * Ticket 29: a host that runs its own team system declares the teams estate ABSENT, so `beam_teams`
 * is never on its disk — and yet it must still be able to seed the demo SUBJECTS, because
 * {@see DemoLoginLinks::for()} is team-agnostic (it mints from a user row by its demo email, with no
 * team involved). The old seeder returned at the teams-table guard BEFORE the user loop, so a
 * teams-absent host got zero demo users and `for()` was null — 18 browser flows then `visit(null)`.
 *
 * The contract this pins: the subjects seed, and `for()` yields a real signed link, WITHOUT any
 * `beam_teams` table being created. Team provisioning and realm reach are the only things the
 * missing estate skips.
 */
it('still seeds the demo subjects, and mints a login link, when the teams estate is absent', function () {
    Schema::dropIfExists((new Team)->getTable());

    app(DemoTeamSeeder::class)->run();

    // The demo subjects exist team-agnostically...
    expect(User::where('email', BeamDemo::email(Role::Owner->value))->exists())->toBeTrue();

    // ...the engine-homed single-link door mints a real signed URL for one...
    expect(app(DemoLoginLinks::class)->for(Role::Owner->value))
        ->not->toBeNull()
        ->toContain('/login-as')
        ->toContain('signature=');

    // ...and NO team estate was fabricated on a host that declared it absent.
    expect(Schema::hasTable((new Team)->getTable()))->toBeFalse();
});
