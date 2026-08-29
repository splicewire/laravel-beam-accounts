<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * **Turning a publish gate off is a CLAIM, and this is the thing that checks it.**
 *
 * `publish_auth_migrations` / `publish_migrations` (renamed from `register_*` at beam-docs-satellite
 * ticket 25) decide whether `->hasMigrations()` declares an estate — i.e. whether `vendor:publish` and
 * `splicewire:beam:install` copy those stubs onto the host's disk. Off does not mean *"this host does
 * not need these tables."* It means **"every member of that estate is already committed on my disk"** —
 * which is exactly what all three starters mean, having published the estate once and committed the
 * output as their tier's schema.
 *
 * ## The failure it exists to end
 *
 * Nothing verified the claim. `laravel-tower-starter` turned `register_auth_migrations` off, and then
 * commit `420f9e0` (*"hand users table ownership to laravel-beam-accounts"*) deleted its committed
 * `create_users_table` **and** `create_passkeys_table` — handing ownership to a package whose publish
 * gate that same repo had turned off. The two halves of that change contradicted each other, both were
 * committed, and **nothing failed until a fresh clone could not build a database at all**: the grants
 * table's `constrained('users')` blew up against a `users` table nothing created, months later, in a
 * different effort, presenting as a docs-propagation problem.
 *
 * The starter's own `config/beam/accounts.php` docblock still claims it *"already ships its OWN
 * bigint-keyed `users` … migrations"*. It ships none. A comment cannot check itself; this can.
 *
 * ## The predicate
 *
 * For each estate whose gate is OFF, every stub the provider would have declared must have a
 * counterpart committed under `database/migrations/**` — matched on the **filename stem** with the
 * timestamp prefix stripped, because a published copy is stamped at install time and its prefix is
 * therefore meaningless for identity. A gate that is ON is not checked at all: the publish pass is the
 * host's coverage, and a missing file there is a publish problem rather than a claim problem.
 *
 * Deliberately **not** a filename-order check — {@see \Splicewire\Beam\Doctor\MigrationOrderingAudit}
 * and `TableOwnershipResolver` own that question. This one asks only whether the file exists at all.
 *
 * ## Severity
 *
 * **Fail.** Unlike a dead config key, there is no benign reading: the host has asserted the files are
 * present and they are not, so the tables they create will never exist on a fresh install. Every host
 * that hits this is one `migrate:fresh` away from the tower outage.
 */
class PublishGateCoverageAudit implements DoctorAudit
{
    public const CHECK = 'beam-accounts.publish-gate-coverage';

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $committed = $this->committedStems();
        $findings = [];

        foreach (BeamAccountsServiceProvider::gatedEstates() as $estate => $stubs) {
            if (BeamAccountsServiceProvider::publishesEstateNamed($estate)) {
                continue;
            }

            $missing = array_values(array_filter(
                $stubs,
                static fn (string $stub): bool => ! in_array(basename($stub), $committed, true),
            ));

            if ($missing === []) {
                continue;
            }

            // Name the key that is ACTUALLY off. The gate is an AND of the modern `publish_*` and the
            // legacy `register_*`, and naming the modern one unconditionally sent readers to a key
            // that reads `true` at their host — see closedGateKeysFor()'s docblock.
            $closed = BeamAccountsServiceProvider::closedGateKeysFor($estate);

            $findings[] = Finding::fail(self::CHECK, sprintf(
                '%s is off, which asserts this host already has that estate '.
                'committed — but %d of %d member(s) are absent from database/migrations/**: %s. '.
                'Publishing is gated off, so nothing will ever create these tables and a fresh install '.
                'will fail on the first migration that references them. Either commit the missing '.
                'copies (publish once with the gate on, then commit the output) or turn the gate back on.',
                $closed === []
                    ? sprintf('`beam.accounts.publish_%s`', $estate)
                    : implode(' and ', array_map(static fn (string $k): string => "`{$k}`", $closed)),
                count($missing),
                count($stubs),
                implode(', ', array_map('basename', $missing)),
            ));
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, 'Every publish gate that is off has its estate committed on disk.')];
        }

        return $findings;
    }

    /**
     * Every committed migration's filename stem, timestamp prefix stripped.
     *
     * The prefix cannot participate: a published copy is stamped `now()` at install time, so the same
     * logical migration has a different prefix on every host and comparing them would report a host
     * that did exactly the right thing. Recurses, because the estate places into `shared/` and
     * `tenant/` subdirectories as well as the flat root.
     *
     * @return list<string>
     */
    protected function committedStems(): array
    {
        $root = database_path('migrations');

        if (! is_dir($root)) {
            return [];
        }

        $stems = [];

        foreach ($this->migrationFiles($root) as $file) {
            $stems[] = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file, '.php'));
        }

        return array_values(array_unique(array_filter($stems)));
    }

    /**
     * @return list<string>
     */
    protected function migrationFiles(string $root): array
    {
        $found = glob($root.'/*.php') ?: [];

        foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $found = array_merge($found, $this->migrationFiles($dir));
        }

        return $found;
    }
}
