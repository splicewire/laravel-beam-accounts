<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * Boots the engine with the key-management module opted IN (the way a satellite host runs
 * it), and asserts the host-facing `splicewire:beam:accounts:mint-key` command is wired and mints a
 * deterministic, reset-surviving row end-to-end.
 */
class KeysModuleEnabledTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PermissionServiceProvider::class,
            PermissionCascadeServiceProvider::class,
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

        // The host opts into managing its own keys.
        $config->set('beam.accounts.keys.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->string('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /** Re-key the table by uuid, the way every splicewire-operated host runs it. */
    protected function useUuidKeyedTokensTable(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tokenable_type');
            $table->string('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_command_is_registered_when_the_module_is_enabled(): void
    {
        $this->assertArrayHasKey('splicewire:beam:accounts:mint-key', $this->app[Kernel::class]->all());
    }

    /**
     * The id is the argument the whole primitive is pinned on, so its definition existing at all
     * is worth asserting directly — a signature typo does not fail loudly, it just drops the
     * argument, and the mint then silently keys every row on nothing.
     */
    public function test_command_declares_the_pinned_id_argument(): void
    {
        $definition = $this->app[Kernel::class]->all()['splicewire:beam:accounts:mint-key']->getDefinition();

        $this->assertTrue($definition->hasArgument('id'));
        $this->assertTrue($definition->getArgument('id')->isRequired());
    }

    /**
     * The console is where a uuid is most at risk: an argument arrives as a string, and a `(int)`
     * on the way into DeterministicToken turns it into 0 — a row keyed wrong, and on Postgres an
     * invalid uuid literal outright. This drives the real command against a uuid-keyed table.
     */
    public function test_command_mints_a_uuid_keyed_row_without_coercing_the_id(): void
    {
        $this->useUuidKeyedTokensTable();

        $uuid = '2b1e7c9a-3f4d-5a6b-8c7d-9e0f1a2b3c4d';

        $this->artisan('splicewire:beam:accounts:mint-key', [
            'id' => $uuid,
            'plaintext' => 'numeroSatelliteServiceToken00000000000v1',
            '--tokenable-id' => 'owner-uuid',
            '--name' => 'numero-satellite',
        ])->expectsOutputToContain($uuid.'|numeroSatelliteServiceToken00000000000v1')
            ->assertSuccessful();

        $this->assertSame($uuid, DB::table('personal_access_tokens')->value('id'));
        $this->assertSame(1, DB::table('personal_access_tokens')->count());

        // Idempotent through the console door too: the second run re-finds the pinned row.
        $this->artisan('splicewire:beam:accounts:mint-key', [
            'id' => $uuid,
            'plaintext' => 'numeroSatelliteServiceToken00000000000v1',
            '--tokenable-id' => 'owner-uuid',
            '--name' => 'numero-satellite',
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_command_mints_a_deterministic_row(): void
    {
        $this->artisan('splicewire:beam:accounts:mint-key', [
            'id' => 990003,
            'plaintext' => 'numeroSatelliteServiceToken00000000000v1',
            '--tokenable-id' => 'owner-uuid',
            '--name' => 'numero-satellite',
        ])->assertSuccessful();

        // Re-run: idempotent, still one row, same credential.
        $this->artisan('splicewire:beam:accounts:mint-key', [
            'id' => 990003,
            'plaintext' => 'numeroSatelliteServiceToken00000000000v1',
            '--tokenable-id' => 'owner-uuid',
            '--name' => 'numero-satellite',
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('personal_access_tokens')->where('id', 990003)->count());
        $this->assertSame(
            hash('sha256', 'numeroSatelliteServiceToken00000000000v1'),
            DB::table('personal_access_tokens')->where('id', 990003)->value('token'),
        );
    }
}
