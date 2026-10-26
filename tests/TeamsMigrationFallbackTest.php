<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Accounts\Concerns\WiresTeamsMigrations;
use Stancl\Tenancy\Tenancy;

// Pest does not set PHPUnit's Composer entrypoint constant. Its isolated-process runner
// needs this path before it can load the configured bootstrap or Configuration Registry.
if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', dirname(__DIR__).'/vendor/autoload.php');
}

/** The migration-registration boundary; no database connection or host provider is booted. */
class TeamsMigrationFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::setInstance(null);
        parent::tearDown();
    }

    public function test_single_host_registers_both_paths_by_default_even_with_stancl_installed(): void
    {
        $app = new Application(__DIR__);
        $app->instance('config', new Repository);
        $resolver = new ConnectionResolver;
        $migrator = new Migrator(new DatabaseMigrationRepository($resolver, 'migrations'), $resolver, new Filesystem);
        $app->instance('migrator', $migrator);
        $shared = $app->databasePath('migrations/shared');
        $migrator->path($shared);

        (new TeamsMigrationRegistrationProvider($app))->boot();

        $this->assertTrue(class_exists(Tenancy::class));
        $this->assertSame([
            $shared,
            $app->databasePath('migrations/teams'),
            $app->databasePath('migrations/tenant'),
        ], $migrator->paths());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_beam_tenancy_keeps_ownership_even_when_the_fallback_is_enabled(): void
    {
        // Isolated class-presence boundary: no tenant provider or tenant DB is booted.
        $this->assertFalse(class_exists('Splicewire\\Beam\\Tenancy\\BeamTenancyServiceProvider'));
        class_alias(TeamsMigrationTenancyPresence::class, 'Splicewire\\Beam\\Tenancy\\BeamTenancyServiceProvider');
        $app = new Application(__DIR__);
        $app->instance('config', new Repository([
            'beam' => ['accounts' => ['central_migration_fallback' => true]],
        ]));
        $resolver = new ConnectionResolver;
        $migrator = new Migrator(new DatabaseMigrationRepository($resolver, 'migrations'), $resolver, new Filesystem);
        $app->instance('migrator', $migrator);
        $shared = $app->databasePath('migrations/shared');
        $migrator->path($shared);

        (new TeamsMigrationRegistrationProvider($app))->boot();

        $this->assertSame([$shared], $migrator->paths());
    }

    public function test_stancl_host_can_keep_tenant_migrations_out_of_the_central_pass(): void
    {
        $app = new Application(__DIR__);
        $app->instance('config', new Repository([
            'beam' => ['accounts' => ['central_migration_fallback' => false]],
        ]));
        $resolver = new ConnectionResolver;
        $migrator = new Migrator(new DatabaseMigrationRepository($resolver, 'migrations'), $resolver, new Filesystem);
        $app->instance('migrator', $migrator);
        $shared = $app->databasePath('migrations/shared');
        $migrator->path($shared);

        (new TeamsMigrationRegistrationProvider($app))->boot();

        $this->assertTrue(class_exists(Tenancy::class));
        $this->assertSame([$shared], $migrator->paths());
    }
}

/** Boots just the owning concern, observing Laravel's actual migrator path API. */
class TeamsMigrationRegistrationProvider extends ServiceProvider
{
    use WiresTeamsMigrations;

    public function boot(): void
    {
        $this->bootTeamsMigrations();
    }
}

class TeamsMigrationTenancyPresence {}
