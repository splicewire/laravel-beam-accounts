<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Tests\Fixtures\UuidPersonalAccessToken;
use Splicewire\Beam\Accounts\Tokens\DelegatedTokenKind;

/**
 * Boots the engine with the key-management module opted IN (the way a satellite host runs it), and asserts the
 * host-facing `splicewire:beam:accounts:mint-key` command is wired and mints a deterministic, reset-surviving key
 * end-to-end, through the machine-token issuer (fleet ADR 0006 C7, credential-scope 04): the KIND names the principal
 * and the abilities, never the console, and nothing it mints is `*`.
 *
 * Before 04 the primitive was `Keys\DeterministicToken` (a query-builder upsert whose abilities defaulted to `*`)
 * and this command took `--tokenable-*` and `--ability`. Its reproducibility properties are kept here: the bearer is
 * a pure function of (id, plaintext), a re-mint is one row with the same credential and its first `created_at`, and
 * a uuid id survives the console.
 */
class KeysModuleEnabledTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // popcorn's shared RegistryIndex singleton — see TestCase::getPackageProviders().
            PopcornServiceProvider::class,
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

        // The host opts into managing its own keys, and enforces scopes (the issuer mints nothing otherwise).
        $config->set('beam.accounts.keys.enabled', true);
        $config->set('beam.accounts.api.scope_enforcement', true);
        $config->set('beam.accounts.tokens.kinds.cs04-host-key', Cs04HostKeyKind::class);
        $config->set('beam.accounts.tokens.kinds.cs04-wildcard-key', Cs04WildcardKeyKind::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        $this->createTokensTable(uuid: false);
    }

    /** Sanctum's shape plus the ALTER stubs the issuer writes (provenance, last four, kind); bigint or uuid key. */
    protected function createTokensTable(bool $uuid): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) use ($uuid) {
            $uuid ? $table->uuid('id')->primary() : $table->id();
            $table->string('tokenable_type');
            $table->string('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->string('last_four', 8)->nullable();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('provenance')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('kind', 64)->nullable();
            $table->timestamps();
        });

        config(['beam.accounts.tokens.model' => $uuid ? UuidPersonalAccessToken::class : PersonalAccessToken::class]);
    }

    public function test_command_is_registered_when_the_module_is_enabled(): void
    {
        $this->assertArrayHasKey('splicewire:beam:accounts:mint-key', $this->app[Kernel::class]->all());
    }

    /**
     * The id is the argument the whole primitive is pinned on, so its definition existing at all is worth asserting
     * directly — a signature typo does not fail loudly, it just drops the argument. The kind is the other half: it,
     * not the console, names the principal and the abilities, so neither `--tokenable-*` nor `--ability` exists.
     */
    public function test_command_declares_the_kind_and_the_pinned_id_and_no_principal_or_abilities(): void
    {
        $definition = $this->app[Kernel::class]->all()['splicewire:beam:accounts:mint-key']->getDefinition();

        $this->assertTrue($definition->hasArgument('kind'));
        $this->assertTrue($definition->getArgument('kind')->isRequired());
        $this->assertTrue($definition->hasArgument('id'));
        $this->assertTrue($definition->getArgument('id')->isRequired());
        $this->assertFalse($definition->hasOption('ability'));
        $this->assertFalse($definition->hasOption('tokenable-id'));
        $this->assertFalse($definition->hasOption('tokenable-type'));
    }

    /**
     * The console is where a uuid is most at risk: an argument arrives as a string, and a `(int)` on the way in turns
     * it into 0 — a row keyed wrong, and on Postgres an invalid uuid literal outright.
     */
    public function test_command_mints_a_uuid_keyed_row_without_coercing_the_id(): void
    {
        $this->createTokensTable(uuid: true);

        $uuid = '2b1e7c9a-3f4d-5a6b-8c7d-9e0f1a2b3c4d';
        $mint = fn () => $this->artisan('splicewire:beam:accounts:mint-key', [
            'kind' => 'cs04-host-key',
            'id' => $uuid,
            'plaintext' => 'numeroSatelliteServiceToken00000000000v1',
            '--name' => 'numero-satellite',
        ]);

        $mint()->expectsOutputToContain($uuid.'|numeroSatelliteServiceToken00000000000v1')->assertSuccessful();

        $this->assertSame($uuid, DB::table('personal_access_tokens')->value('id'));

        // Idempotent through the console door too: the second run re-finds the pinned row.
        $mint()->assertSuccessful();

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame((string) UuidPersonalAccessToken::findToken($uuid.'|numeroSatelliteServiceToken00000000000v1')?->getKey(), $uuid);
    }

    /** A bigint-keyed table takes a numeric id verbatim: the identical bearer as a satellite's const would mint. */
    public function test_command_mints_one_reset_surviving_row_of_the_kind_and_keeps_its_first_created_at(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $mint = fn () => $this->artisan('splicewire:beam:accounts:mint-key', [
            'kind' => 'cs04-host-key',
            'id' => '990003',
            'plaintext' => 'numeroSatelliteServiceToken00000000000v1',
            '--name' => 'numero-satellite',
        ]);

        $mint()->expectsOutputToContain('990003|numeroSatelliteServiceToken00000000000v1')->assertSuccessful();
        Carbon::setTestNow('2026-11-21 10:00:00');
        $mint()->assertSuccessful();
        Carbon::setTestNow();

        $row = DB::table('personal_access_tokens')->where('id', 990003)->first();

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame(hash('sha256', 'numeroSatelliteServiceToken00000000000v1'), $row->token);
        $this->assertSame('cs04-host-key', $row->kind);
        $this->assertSame(TokenProvenance::Service->value, $row->provenance);
        $this->assertSame(['host.read', 'host.write'], json_decode($row->abilities, true));
        $this->assertSame(Cs04HostKeyKind::principal()->getKey(), $row->tokenable_id);
        $this->assertStringStartsWith('2026-11-20', (string) $row->created_at);
    }

    /** C7: the console cannot mint an undeclared kind, `*`, a same-principal kind (it has no principal), or while unenforced. */
    public function test_command_refuses_an_undeclared_kind_a_wildcard_kind_a_personal_token_and_an_unenforced_host(): void
    {
        $args = fn (string $kind) => ['kind' => $kind, 'id' => '990004', 'plaintext' => 'p'];

        $this->artisan('splicewire:beam:accounts:mint-key', $args('cs04-nothing'))->expectsOutputToContain('cs04-nothing')->assertFailed();
        $this->artisan('splicewire:beam:accounts:mint-key', $args('cs04-wildcard-key'))->assertFailed();
        $this->artisan('splicewire:beam:accounts:mint-key', $args('personal-access-token'))->assertFailed();

        config(['beam.accounts.api.scope_enforcement' => false]);
        $this->artisan('splicewire:beam:accounts:mint-key', $args('cs04-host-key'))->assertFailed();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }
}

/** A host's console key: the host's own service principal, with a declared set, minted only from a console. */
class Cs04HostKeyKind extends DelegatedTokenKind
{
    public static function principal(): User
    {
        return User::query()->firstOrCreate(['email' => 'host-key@cs04.test'], ['name' => 'Host key']);
    }

    public function provenance(): TokenProvenance
    {
        return TokenProvenance::Service;
    }

    public function issuanceAbility(): string
    {
        return 'cs04-host-key:issue';
    }

    public function boundSubject(?Authenticatable $actor): mixed
    {
        return 'host';
    }

    public function resolveTarget(?Authenticatable $actor, mixed $subject): ?Model
    {
        return $subject === 'host' ? self::principal() : null;
    }

    public function authorizes(?Authenticatable $actor, Model $target, mixed $subject): bool
    {
        return $actor === null && app()->runningInConsole();
    }

    public function abilities(Model $target, mixed $subject): array
    {
        return ['host.read', 'host.write'];
    }
}

class Cs04WildcardKeyKind extends Cs04HostKeyKind
{
    public function abilities(Model $target, mixed $subject): array
    {
        return ['*'];
    }
}
