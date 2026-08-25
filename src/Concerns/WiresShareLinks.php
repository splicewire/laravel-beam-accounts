<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Http\Controllers\ShareLinkController;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Sharing\ShareLinkScopes;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresShareLinks
{
    /**
     * The reusable link-only front door (tracer 06): a PUBLIC `GET /s/{token}` that resolves a
     * ShareLink through the host-registered {@see ShareLinkScopes}. Gated by
     * `beam.accounts.share_links.enabled` — the ShareLink primitive stays callable from PHP
     * either way; this only mounts the guest route.
     */
    #[Chained('boot', order: 110)]
    protected function bootShareLinks(): void
    {
        if (! config('beam.accounts.share_links.enabled', true)) {
            return;
        }

        Route::middleware('web')->group(function () {
            Route::get('/s/{token}', [ShareLinkController::class, 'resolve'])->name('beam.share-link.resolve');
        });
    }
}
