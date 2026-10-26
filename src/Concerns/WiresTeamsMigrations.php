<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresTeamsMigrations
{
    /**
     * `teams/`/`tenant/` ship as publish-only stubs (spatie/laravel-package-tools `hasMigrations()`)
     * into subdirectories the stock framework migrator never recurses into — the SAME footgun
     * `shared/` has (beam-install-turnkey trap 1), just without a fix until now: beam-core's own
     * `BeamServiceProvider` registers `database/migrations/shared` for a single-tenant host, but
     * nothing registered `teams/`/`tenant/`, so a host that ran `vendor:publish` + `migrate` (or
     * even `splicewire:beam:install`, whose own verify-provisioning pass has no trap for this) got
     * "Nothing to migrate" silently — the whole accounts/teams estate never landed.
     *
     * Beam tenancy owns these paths when its provider is installed. Hosts with their own
     * tenancy routing can disable `beam.accounts.central_migration_fallback` instead: the
     * presence of Stancl's classes alone cannot identify such a host, because Accounts also
     * requires Stancl on single-tenant installs. This only controls the central fallback;
     * publishing stubs and paths registered by other providers remain unchanged.
     * Missing directories are harmless before the first publish.
     */
    #[Chained('boot', order: 150)]
    protected function bootTeamsMigrations(): void
    {
        if (class_exists('Splicewire\Beam\Tenancy\BeamTenancyServiceProvider')
            || ! config('beam.accounts.central_migration_fallback', true)) {
            return;
        }

        $this->loadMigrationsFrom(database_path('migrations/teams'));
        $this->loadMigrationsFrom(database_path('migrations/tenant'));
    }
}
