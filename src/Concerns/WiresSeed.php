<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Seed\SeedStep;

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
     * **Which environment gets demo subjects is the Seeding Scenario's question** (seeding-scenarios 01): the step
     * registers with the `demo` membership, so only a scenario that includes `demo` runs it — `local` out of the
     * box, and `baseline` (which production, staging and testing fall to) never. This provider no longer reads
     * the environment: the null-means-not-production resolution it had, mirroring {@see BeamDemo::enabled()}, is
     * gone, and `BeamDemo::enabled()` itself (the login door, not seeding) is unchanged.
     *
     * The config gate `beam.accounts.demo.seed_users` stays for "this step cannot run here": it is the host's
     * explicit opt-out (`false`) AND-ed with the teams estate below. Null, its default from
     * `env('ACCOUNT_SEED_DEMO_USERS')`, now means "no objection". The resolved boolean is written back onto the
     * same key so the manifest's raw `config($gate)` check reads the effective value.
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

        // The host's EXPLICIT decision wins outright; only a `null` (the default from
        // `env('ACCOUNT_SEED_DEMO_USERS')`) hands the choice to the teams estate below.
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
        // Why `null` DEFERS but an explicit `true` does NOT (ticket 29): the demo SUBJECTS are
        // team-agnostic and, as of {@see DemoTeamSeeder::run()}, seed BEFORE that seeder's teams-table
        // guard and never fabricate `beam_teams`. So a host that runs its own team system — teams
        // estate `'absent'` — can safely opt IN to login-able demo subjects by setting the key to
        // `true` (the flagship does, for its accounts browser flows). It is not opted in by DEFAULT:
        // a bare `absent` host with no opinion stays off, so existing such hosts are not surprised.
        // When that unopinionated-but-demo-on case leaves the links empty, it is made VISIBLE rather
        // than silent by {@see \Splicewire\Beam\Accounts\Doctor\DemoSeedReachabilityAudit}.
        //
        // This stays a pure CONFIG read: it resolves in `boot`, where no database connection is
        // guaranteed and a `Schema::hasTable()` here would put a schema query on every request. The
        // "are the tables actually there" half is capability rather than policy, and lives at the
        // point of use — {@see DemoTeamSeeder::run()} skips its TEAM step with a message, exactly as
        // its ungated sibling {@see RolePermissionsSeeder} already did.
        $flag = config('beam.accounts.demo.seed_users');

        if ($flag === null) {
            $enabled = BeamAccountsServiceProvider::estateDeclaredPresent('migrations');
        } else {
            $enabled = (bool) $flag;
        }

        config(['beam.accounts.demo.seed_users' => $enabled]);

        $this->app->make(BeamSeedManifest::class)->register(
            package: 'splicewire/laravel-beam-accounts',
            seederClass: DemoTeamSeeder::class,
            order: 10,
            configGate: 'beam.accounts.demo.seed_users',
            memberships: SeedStep::DEMO,
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
