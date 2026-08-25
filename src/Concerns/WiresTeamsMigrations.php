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
     * Mirrors beam-core's `sharedMigrationsOwnedByTenancy()` guard exactly: GUARDED on the tenancy
     * provider not being present, so this never double-registers on a multi-tenant host (that
     * package owns routing `tenant/` into its per-tenant pass, and `teams/` into whichever side
     * `config/beam/accounts.php`'s multitenancy placement calls for). A single-tenant host — every
     * host today; no consumer has ever placed this estate per-tenant (see
     * `teams/create_visibilities_table`'s docblock) — runs both on its one central connection.
     * `loadMigrationsFrom` over an empty/missing directory is a harmless no-op, so this is safe
     * before the first publish too.
     */
    #[Chained('boot', order: 150)]
    protected function bootTeamsMigrations(): void
    {
        if (class_exists('Splicewire\Beam\Tenancy\BeamTenancyServiceProvider')) {
            return;
        }

        $this->loadMigrationsFrom(database_path('migrations/teams'));
        $this->loadMigrationsFrom(database_path('migrations/tenant'));
    }
}
