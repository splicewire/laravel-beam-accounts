<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Support\Facades\Route;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Ops\LogInAsUser;
use Splicewire\Beam\Facades\Particle;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresDemo
{
    /**
     * The demo verification path — a signed login-as route that lands you in the app as a
     * known subject. Registered only when demo affordances are live (non-production by
     * default — the `beam.accounts.demo.enabled` config gate). Outside local/testing
     * the controller requires a signed link (the `splicewire:beam:accounts:login-as` command mints one), so
     * it opens no back door in a preview deploy. An engine affordance, config-gated — a
     * satellite no longer hand-wires it.
     */
    #[Chained('boot', order: 80)]
    protected function bootDemo(): void
    {
        if (! BeamDemo::enabled()) {
            return;
        }

        // The signed browser link now targets the OPERATION route (`users/{id}/op/login-as`) rather
        // than a bespoke `account/login-as/{subject}` controller. Mounted GET because a human clicks
        // it — `particleOp` takes the verb as an option precisely so a signed magic-link can be one.
        // The op is already registered (see bootFrameResources), so this mounts by bare name.
        //
        // A host wanting the JSON/API half mounts the same op as POST in its own group; the handler
        // returns AuthUserData there and a redirect here, off one declaration.
        // `particleOps` with a runtime object REGISTERS and MOUNTS in one call, so the operation
        // and its route share one demo gate — when demo is off neither exists, which is what the
        // retired bespoke route did too.
        Route::middleware('web')->group(function () {
            Particle::ops('users', 'users', [LogInAsUser::class], ['method' => 'get']);
        });
    }
}
