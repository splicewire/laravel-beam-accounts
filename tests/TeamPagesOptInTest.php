<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\Route;

/**
 * The team pages (`teams.create`, `invitations.accept`) render Inertia pages that live in
 * `@splicewire/beam-inertia`'s page map, not in the host. A host that resolves pages only from its own
 * `resources/js/pages` cannot render them, so the package's auto-mount offers them only when the host
 * opts in with `beam.accounts.teams.pages` (sites UX sweep F52: `/teams/create` was a blank page or a
 * 500 on four hosts). An explicit `Route::splicewireTeamRoutes()` call is itself the opt-in.
 *
 * The rest of this suite runs opted in ({@see TestCase::defineEnvironment()}); this class boots with the
 * shipped default.
 */
class TeamPagesOptInTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $shipped = require dirname(__DIR__).'/config/beam/accounts.php';
        $app['config']->set('beam.accounts.teams.pages', $shipped['teams']['pages'] ?? null);
    }

    public function test_the_shipped_default_leaves_the_team_pages_off(): void
    {
        $shipped = require dirname(__DIR__).'/config/beam/accounts.php';

        $this->assertArrayHasKey('pages', $shipped['teams']);
        $this->assertFalse($shipped['teams']['pages']);
    }

    public function test_the_auto_mount_offers_no_team_route_by_default_and_keeps_the_settings_surface(): void
    {
        foreach (['teams.create', 'teams.store', 'invitations.accept', 'invitations.redeem'] as $name) {
            $this->assertFalse(Route::has($name), "route [{$name}] is mounted without the opt-in");
        }

        $this->assertTrue(Route::has('profile.edit'));
    }

    public function test_an_explicit_macro_call_still_mounts_them(): void
    {
        Route::splicewireTeamRoutes();
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has('teams.create'));
        $this->assertTrue(Route::has('invitations.accept'));
    }
}
