<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Console\GenerateOidcSigningKeyCommand;
use Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter;
use Splicewire\Beam\Accounts\Oidc\SigningKey;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresOidc
{
    /**
     * The per-host OIDC-issuer module (tenant-database-upsell ticket 16): a self-hosted
     * `/.well-known/openid-configuration` + `/.well-known/jwks.json` pair so this host can
     * prove its own identity to an OIDC-federation consumer (GCP Workload Identity Federation,
     * most immediately) with no static secret ever leaving the box. Default-off, mirroring the
     * `keys` seam — the primitives (SigningKey/IdentityTokenMinter) are always bound above;
     * only the public routes + the host-facing key-generation command are gated here.
     *
     * Registered WITHOUT the `web` middleware group deliberately: a federation consumer polls
     * this endpoint on its own schedule (GCP caches a WIF provider's JWKS but still refetches
     * periodically), and there is nothing here a session/CSRF stack needs to protect — the
     * entire point of a JWKS route is that it's safe to serve to anyone, unauthenticated.
     */
    #[Chained('boot', order: 100)]
    protected function bootOidc(): void
    {
        if (! config('beam.accounts.oidc.enabled', false)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateOidcSigningKeyCommand::class]);
        }

        // ⚠️ `dirname(__DIR__, 2)`, not `__DIR__.'/..'`: this lives in src/Concerns/, one level deeper
        // than the provider it was extracted from, so a `__DIR__`-relative path silently resolves
        // one directory short. php -l passes and only a runtime read fails.
        Route::group([], dirname(__DIR__, 2).'/routes/oidc.php');
    }
}
