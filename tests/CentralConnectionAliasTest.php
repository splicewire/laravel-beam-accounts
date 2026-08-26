<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Models\User as BeamUser;
use Splicewire\Beam\Accounts\Tests\Fixtures\AliasProbeProvider;
use Splicewire\Beam\BeamServiceProvider;

/**
 * The `central` alias (map ticket 79): {@see BeamUser} pins a LITERAL connection name, so at a host
 * that never defined `central` — 10 of the estate's 12 Herd hosts and all four starters when
 * measured, 2026-08-22 — merely RESOLVING the model threw
 * `InvalidArgumentException: Database connection [central] not configured.`
 *
 * The package's own suite never caught this because every other test drives
 * {@see Fixtures\User}, a plain Authenticatable with no pin — the pinned base model was, until this
 * file, unexercised. Same shape as the estate: the wall is latent because nothing reaches the model
 * until a host registers `UserData` or points `beam.accounts.user_model` at it.
 *
 * Deliberately a FILE-backed sqlite rather than `:memory:`: two connections both configured as
 * `:memory:` are two SEPARATE databases, so an in-memory suite can prove the connection RESOLVES but
 * never that the alias reaches the same data. {@see self::test_a_write_through_the_pinned_model_lands_in_the_default_database()}
 * is the one that matters.
 *
 * The guard branches are driven through {@see AliasProbeProvider} rather than through
 * `defineEnvironment` — see that class for why a Testbench `database.*` override cannot reach the
 * register-time read.
 */
class CentralConnectionAliasTest extends Orchestra
{
    protected string $databasePath;

    protected function getPackageProviders($app): array
    {
        return [
            // popcorn's shared RegistryIndex singleton — see TestCase::getPackageProviders().
            PopcornServiceProvider::class,
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            LaravelDataServiceProvider::class,
            // beam-core, for the `particleOps` route attribute the accounts provider's boot uses.
            BeamServiceProvider::class,
            BeamAccountsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->databasePath ??= tempnam(sys_get_temp_dir(), 'beam-accounts-central-').'.sqlite';
        touch($this->databasePath);

        tap($app['config'], function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => $this->databasePath,
                'prefix' => '',
            ]);
        });
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->databasePath) && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    /**
     * Re-run the alias against the config state this test has set up, as the register phase would
     * see it at a real host, and drop any connection already opened under the old block.
     */
    protected function aliasAgainstCurrentConfig(): void
    {
        DB::purge('central');

        (new AliasProbeProvider($this->app))->probeCentralConnectionAlias();
    }

    public function test_the_provider_registers_central_with_no_host_action(): void
    {
        // Nothing in defineEnvironment() defined `central`; the provider did, at register time.
        $this->assertNotNull(config('database.connections.central'));
        $this->assertSame('sqlite', config('database.connections.central.driver'));
    }

    public function test_the_pinned_model_resolves_its_connection(): void
    {
        $this->assertSame('central', (new BeamUser)->getConnection()->getName());
    }

    public function test_the_alias_is_a_copy_of_the_default_connection(): void
    {
        config(['database.connections.central' => null]);

        $this->aliasAgainstCurrentConfig();

        $this->assertSame(
            config('database.connections.testing'),
            config('database.connections.central'),
        );
    }

    public function test_a_host_defined_central_block_wins(): void
    {
        config(['database.connections.central' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'host_',
        ]]);

        $this->aliasAgainstCurrentConfig();

        $this->assertSame('host_', config('database.connections.central.prefix'));
        $this->assertSame(':memory:', config('database.connections.central.database'));
    }

    public function test_no_alias_is_fabricated_when_central_is_itself_the_default(): void
    {
        config([
            'database.default' => 'central',
            'database.connections.central' => null,
        ]);

        $this->aliasAgainstCurrentConfig();

        // Nothing to copy FROM — the missing block IS the default block, a real misconfiguration
        // whose own error message is more useful than a self-referential copy.
        $this->assertNull(config('database.connections.central'));
    }

    /**
     * Reads through the pinned model against a row written over the DEFAULT connection — the
     * direction is deliberate. A `save()` on the base model itself is impossible regardless of the
     * connection: `ResourceSyncing::bootResourceSyncing()` registers a `saved` listener typed
     * `Syncable`, and the base intentionally does not implement it (its docblock: the contract binds
     * to host-owned `Tenant`/pivot semantics, so the concrete `App\Models\User` declares it). So the
     * base is a READ/resolve surface until a host subclasses it, which is exactly the surface
     * `UserData` — the only package-estate consumer — uses.
     */
    public function test_the_pinned_model_reads_the_default_database(): void
    {
        config(['database.connections.central' => null]);

        $this->aliasAgainstCurrentConfig();

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('google_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::connection(config('database.default'))->table('users')->insert([
            'id' => '01a0317c-63b1-72c7-be04-422d4c4d35b3',
            'name' => 'Central Principal',
            'email' => 'principal@example.test',
        ]);

        // Same row, read over `central` — so the alias is an alias and not a second, parallel
        // database. Under two `:memory:` blocks this assertion would be 0.
        $this->assertSame(
            'principal@example.test',
            BeamUser::query()->sole()->email,
        );
    }
}
