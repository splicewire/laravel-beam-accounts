<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Contracts\MembershipContract;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;

/**
 * Boots the engine with the auth surface config-gated OFF — the way the platform app consumes it
 * (Sanctum auth over its own `tenant_users`). Deliberately does NOT register Fortify so the
 * provider is exercised on the false path, and asserts the code primitive (contracts/enum/models)
 * is still available.
 *
 * The former `register_migrations`/`register_auth_migrations` config toggles (and the assertion
 * that gating them off left no beam-accounts migration path registered) are GONE: migrations are
 * now publish-only stubs (estate-wide convention) — never auto-loaded via `loadMigrationsFrom()`
 * regardless of config, so that assertion's whole premise (a config-gated migration estate) no
 * longer exists. {@see \Splicewire\Beam\Accounts\Tests\Doctor\BeamAccountsMigrationsAuditTest}
 * is the doctor-audit test that now covers "no migrations get auto-loaded" mechanically.
 */
class ConfigGateTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            // NOTE: no FortifyServiceProvider — a host consuming only the primitive
            // does not boot Fortify at all.
            BeamAccountsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('beam.accounts.bootstrap_fortify', false);
        $config->set('beam.accounts.register_routes', false);
    }

    public function test_provider_boots_without_fortify(): void
    {
        // No settings routes were mounted.
        $this->assertFalse(Route::has('profile.edit'));

        // Fortify actions were not wired: bootFortify() returned early, so the engine's
        // CreateNewUser action was never bound to Fortify's CreatesNewUsers contract.
        $this->assertFalse($this->app->bound(CreatesNewUsers::class));
    }

    public function test_code_primitive_is_still_available(): void
    {
        // The contracts/enum/models load and relate regardless of the auth surface.
        $this->assertContains(TeamContract::class, class_implements(Team::class));
        $this->assertContains(MembershipContract::class, class_implements(Membership::class));
        $this->assertSame(['owner', 'admin', 'member'], Role::values());
    }
}
