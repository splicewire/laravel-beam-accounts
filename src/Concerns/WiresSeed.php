<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Seed\BeamSeedManifest;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresSeed
{
    /**
     * Register the {@see DemoTeamSeeder} into beam's package-registered seed manifest (splicewire:beam:seed)
     * so a host's `DatabaseSeeder` no longer hand-calls it by class — it just runs `splicewire:beam:seed`
     * and every beam-* package's seeder fires, each config-gated.
     *
     * The gate is the config key `beam.accounts.demo.seed_users`. It defaults to `env('ACCOUNT_SEED_DEMO_USERS')`
     * (null), and here — mirroring {@see BeamDemo::enabled()} — a null resolves to on-everywhere-but-production, so
     * a production `beam:seed` never fabricates demo subjects while dev/preview seed them by default. Explicit
     * config wins; the resolved boolean is written back onto the same key so the manifest's raw `config($gate)`
     * check reads the effective value.
     *
     * Inert (silently skipped) unless beam-core's {@see BeamSeedManifest} is present — a beam-accounts host
     * composed without the seed command pays nothing.
     */
    #[Chained('boot', order: 140)]
    protected function bootSeed(): void
    {
        if (! class_exists(BeamSeedManifest::class)) {
            return;
        }

        // Resolve the null → non-production fallback ONCE (config($gate) can't run the environment logic),
        // and write it back so the manifest gate reads a concrete boolean.
        $flag = config('beam.accounts.demo.seed_users');
        $enabled = $flag !== null ? (bool) $flag : ! $this->app->environment('production');

        // AND the demo gate with the TEAMS ESTATE, which this seeder writes into (`beam_teams` /
        // `beam_memberships`). A host that runs its own team system declares that estate ABSENT
        // precisely so those tables are never created on its disk (the flagship's
        // `config/beam/accounts.php` says so in terms: "the engine's teams/memberships tables must
        // never be created here"). Gating on the demo key alone made the two facts contradict — the
        // demo gate is on everywhere but production, so at such a host the seeder RAN and died on
        // `relation "beam_teams" does not exist`, measured at ~/Herd/splicewire-app where
        // `splicewire:beam:seed` reported 3 seeded / 1 FAILED.
        //
        // ⚠️ The estate reading is `estateDeclaredPresent()`, NOT `publishesEstateNamed()`. The gate
        // has three values and only `'absent'` declines the estate: `false` means "already committed
        // on my disk", a host that carries the migrations in its own repository. Reading the publish
        // flag collapsed those two and stopped seeding every such host — measured on a fresh
        // `laravel-tower-starter` install (ux-demo-convergence `G1-TOWER-FRESH-INSTALL`, 2026-09-12):
        // tables migrated, `ACCOUNT_SEED_DEMO_USERS=true`, and `beam:seed` reported a gated skip, so
        // `demo-owner` had no team and 403'd out of `/operator`. Both spellings of the key are read
        // by the helper, since naming only the modern one sends a reader to a key that is `true` at
        // their host (the mistake beam-facade 155 spent a section on).
        //
        // This stays a pure CONFIG read: it resolves in `boot`, where no database connection is
        // guaranteed and a `Schema::hasTable()` here would put a schema query on every request. The
        // "are the tables actually there" half is capability rather than policy, and lives at the
        // point of use — {@see DemoTeamSeeder::run()} skips itself with a message, exactly as its
        // ungated sibling {@see RolePermissionsSeeder} already did.
        $enabled = $enabled && BeamAccountsServiceProvider::estateDeclaredPresent('migrations');

        config(['beam.accounts.demo.seed_users' => $enabled]);

        $this->app->make(BeamSeedManifest::class)->register(
            package: 'splicewire/laravel-beam-accounts',
            seederClass: DemoTeamSeeder::class,
            order: 10,
            configGate: 'beam.accounts.demo.seed_users',
        );

        // The role-permission backfill — a SECOND step from this package, and therefore a second
        // manifest key: the registry is keyed by coordinate and `OnKeyDuplicate::Supersede`, so
        // re-using the package name would silently replace the demo step with this one. The key is
        // a relative-URI coordinate under the package's own name, which is what `RelativeUriKey`
        // already accepts for the slash in a composer name.
        //
        // UNGATED, unlike the demo step, and ordered ahead of it: it fabricates no subjects, it
        // re-derives authorization for roles the host already has, so a production seed wants it —
        // and it must not be the thing that repairs a host only when demo mode is on. It is
        // nonetheless AND-ed with the same teams-estate fact, since it syncs team-scoped roles; a
        // host that declared that estate absent runs its own team system and has no rows for it to
        // touch. Same reading as above: a host that committed the estate itself still has them.
        if (BeamAccountsServiceProvider::estateDeclaredPresent('migrations')) {
            $this->app->make(BeamSeedManifest::class)->register(
                package: 'splicewire/laravel-beam-accounts/role-permissions',
                seederClass: RolePermissionsSeeder::class,
                order: 5,
            );
        }
    }
}
