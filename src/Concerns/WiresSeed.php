<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
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

        // AND the demo gate with the TEAMS ESTATE gate. This seeder writes into `beam_teams` /
        // `beam_memberships`, which exist only where the `migrations` estate published — and a host
        // that runs its own team system turns that estate OFF precisely so those tables are never
        // created on its disk (the flagship's `config/beam/accounts.php` says so in terms:
        // "the engine's teams/memberships tables must never be created here").
        //
        // Gating on the demo key alone made those two facts contradict: the demo gate is on
        // everywhere but production, so at such a host the seeder RAN and died on
        // `relation "beam_teams" does not exist` — measured at ~/Herd/splicewire-app, where
        // `splicewire:beam:seed` reported 3 seeded / 1 FAILED. The schema was not missing; the
        // seeder was asking for an estate the host had deliberately declined.
        //
        // Read through publishesEstateNamed() rather than the raw key: the gate is the AND of the
        // modern `publish_*` and legacy `register_*` spellings, and naming only one sends a reader
        // to a key that is `true` at their host (the mistake beam-facade 155 spent a section on).
        $enabled = $enabled && BeamAccountsServiceProvider::publishesEstateNamed('migrations');

        config(['beam.accounts.demo.seed_users' => $enabled]);

        $this->app->make(BeamSeedManifest::class)->register(
            package: 'splicewire/laravel-beam-accounts',
            seederClass: DemoTeamSeeder::class,
            order: 10,
            configGate: 'beam.accounts.demo.seed_users',
        );
    }
}
