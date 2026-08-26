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
 * The `central` alias, INHERITED (beam-facade ticket 96; originally ticket 79, registered here).
 *
 * The alias itself moved to
 * {@see \Splicewire\Beam\BeamServiceProvider::registerCentralConnectionAlias()} — the tier that
 * declares a pin owns making it resolvable, and beam-core declares one of its own
 * (`CentralActivityLog`) that no registration in this package could reach. Core's own
 * `CentralConnectionAliasTest` owns the alias RULES (the two no-op guards, host-block-wins, the
 * copy). This file owns the only claim that is this package's: **{@see BeamUser}'s pin still
 * resolves, and still reaches the default database, purely by requiring beam-core.**
 *
 * That is a real regression surface rather than a restatement. Nothing else in this suite exercises
 * the pinned base model — every other test drives {@see Fixtures\User}, a plain Authenticatable with
 * no pin — so if the move is ever undone from the wrong end, or core stops registering in
 * `packageRegistered()`, this is the file that goes red.
 *
 * Deliberately a FILE-backed sqlite rather than `:memory:`: two connections both configured as
 * `:memory:` are two SEPARATE databases, so an in-memory suite can prove the connection RESOLVES but
 * never that the alias reaches the same data.
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
            // beam-core, for the `particleOps` route attribute the accounts provider's boot uses —
            // and, since ticket 96, for the `central` alias this file asserts is inherited.
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

    public function test_central_is_registered_without_this_package_registering_it(): void
    {
        // Nothing in defineEnvironment() defined `central`, and nothing in this package does either
        // any more — beam-core's provider did, at register time, one tier down.
        $this->assertNotNull(config('database.connections.central'));
        $this->assertSame('sqlite', config('database.connections.central.driver'));
    }

    public function test_the_pinned_auth_principal_resolves_its_connection(): void
    {
        $this->assertSame('central', (new BeamUser)->getConnection()->getName());
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
    public function test_the_pinned_auth_principal_reads_the_default_database(): void
    {
        // Realign the alias against the config THIS test set up — a Testbench ordering artifact,
        // not a property of the code. {@see AliasProbeProvider}.
        config(['database.connections.central' => null]);
        DB::purge('central');
        (new AliasProbeProvider($this->app))->probeCentralConnectionAlias();

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

        // Same row, read over `central` — so the inherited alias is an alias and not a second,
        // parallel database. Under two `:memory:` blocks this assertion would be 0.
        $this->assertSame(
            'principal@example.test',
            BeamUser::query()->sole()->email,
        );
    }
}
