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
 * Boots the engine with the auth surface + schema gated OFF — the way the platform app
 * consumes it (Sanctum auth over its own `tenant_users`, engine tables never created).
 * Deliberately does NOT register Fortify so the provider is exercised on the false path,
 * and asserts the code primitive (contracts/enum/models) is still available.
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

        // The host gates the auth surface + schema off.
        $config->set('beam.accounts.bootstrap_fortify', false);
        $config->set('beam.accounts.register_migrations', false);
        $config->set('beam.accounts.register_routes', false);
    }

    public function test_provider_boots_without_fortify_or_migrations(): void
    {
        // Boot reached here without wiring Fortify or loading migrations, so the engine
        // tables were never registered as pending migrations.
        $pending = collect($this->app['migrator']->paths());
        $this->assertTrue(
            $pending->every(fn ($path) => ! str_contains($path, 'beam-accounts')),
            'beam-accounts migrations must not be registered when register_migrations=false',
        );

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
