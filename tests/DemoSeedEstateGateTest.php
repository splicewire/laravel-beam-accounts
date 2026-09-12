<?php

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Models\Team;

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
 * The one host that must never be seeded: `splicewire-app` runs its own team system over
 * `tenant_users` and declares the engine's teams estate absent. That is the outage the AND was
 * added for (`splicewire:beam:seed` reported 3 seeded / 1 FAILED on `relation "beam_teams" does not
 * exist`), and it stays closed — through the third state, which is the thing that actually says so.
 */
it('closes the gate when the host declares the teams estate absent', function () {
    config([
        'beam.accounts.publish_migrations' => 'absent',
        'beam.accounts.demo.seed_users' => true,
    ]);

    rebootAccountsProvider();

    expect(BeamAccountsServiceProvider::estateDeclaredPresent('migrations'))->toBeFalse()
        ->and(config('beam.accounts.demo.seed_users'))->toBeFalse();
});

it('closes the gate on the third state written through register_*', function () {
    config([
        'beam.accounts.register_migrations' => 'absent',
        'beam.accounts.demo.seed_users' => true,
    ]);

    rebootAccountsProvider();

    expect(config('beam.accounts.demo.seed_users'))->toBeFalse();
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
