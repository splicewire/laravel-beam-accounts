<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresOperatorShell
{
    /**
     * The OOTB `/operator` front-end realm — the piece "install beam, the operator realm just works"
     * was still missing (ADR-0156's `#[OperatorRealm]` preset + `DefaultEntitlementResolver`'s
     * `os.operate` entitlement already exist; nothing rendered anything at the route). A thin stats
     * roll-up landing, matching `laravel-beam-starter`'s own hand-authored `operator/dashboard.tsx` —
     * NOT the windowed `/os` desktop (retired; `@splicewire/beam-ux/shell`'s `DefaultOsDesktop` still
     * exists for a host that wants that shape, it just isn't what this route mounts).
     *
     * Two independent overrides, mirroring `bootDemo()`'s idiom:
     *  - `config('beam.accounts.operator_shell.enabled', true)` — a host turns this off and defines its
     *    own `/operator` entirely.
     *  - `Route::has('operator.home')` — a host that already named its own route `operator.home` (e.g.
     *    by copying this route into its own `routes/web.php` to customize it) is never double-registered.
     *
     * The page itself (`resources/js/pages/operator/dashboard.tsx`) ships as a publish-only stub — see
     * {@see self::packageBooted()}'s `publishes()` call below — so `splicewire:beam:install` syncs the
     * real .tsx file onto the host's disk (editable afterward like any other page) instead of the
     * package trying to inject an un-editable component from node_modules.
     */
    #[Chained('boot', order: 160)]
    protected function bootOperatorShell(): void
    {
        if (! config('beam.accounts.operator_shell.enabled', true)) {
            return;
        }

        if (Route::has('operator.home')) {
            return;
        }

        $this->publishes([
            // ⚠️ `dirname(__DIR__, 2)`, not `__DIR__.'/..'`: this lives in src/Concerns/, one level deeper
            // than the provider it was extracted from, so a `__DIR__`-relative path silently resolves
            // one directory short. php -l passes and only a runtime read fails.
            dirname(__DIR__, 2).'/stubs/js/pages/operator/dashboard.tsx' => resource_path('js/pages/operator/dashboard.tsx'),
        ], 'beam-accounts-operator-shell');

        Route::middleware(['web', 'auth', 'can:entitlement:os.operate'])
            ->get('/operator', function (Request $request) {
                $user = $request->user();
                $model = BeamAccounts::userModel();
                $props = [
                    'staff' => ['name' => $user->name, 'email' => $user->email],
                    'stats' => ['users' => $model::count()],
                ];

                // Opaque by default: no host file required at all, server-rendered Blade, always
                // available the moment the package is installed. The moment a host publishes (or
                // hand-authors) resources/js/pages/operator/dashboard.tsx — ejecting into a real,
                // editable Inertia page — this prefers THAT instead, with no route change needed.
                if (is_file(resource_path('js/pages/operator/dashboard.tsx'))) {
                    return Inertia::render('operator/dashboard', $props);
                }

                return view('beam-accounts::operator-shell', $props);
            })
            ->name('operator.home');
    }
}
