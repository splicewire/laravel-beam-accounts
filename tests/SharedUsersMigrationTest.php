<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SharedUsersMigrationTest extends TestCase
{
    private Manager $database;

    private ?Container $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApplication = Facade::getFacadeApplication();
        $this->database = new Manager;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $container = $this->database->getContainer();
        $container->instance('db', $this->database->getDatabaseManager());
        $container->bind('db.schema', fn () => $this->database->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);

        parent::tearDown();
    }

    public function test_up_leaves_a_tenant_without_users_unchanged(): void
    {
        $this->migration()->up();

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertSame([], Schema::getTableListing());
    }

    public function test_down_leaves_a_tenant_without_users_unchanged(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertSame([], Schema::getTableListing());
    }

    public function test_existing_users_keep_the_column_lifecycle_and_their_data(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $this->database->getConnection()->table('users')->insert(['id' => 1, 'name' => 'Existing owner']);

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('users', 'current_team_id'));
        $this->database->getConnection()->table('users')->where('id', 1)->update(['current_team_id' => 7]);
        $this->assertSame(7, $this->database->getConnection()->table('users')->value('current_team_id'));

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('users', 'current_team_id'));
        $this->assertSame('Existing owner', $this->database->getConnection()->table('users')->value('name'));
    }

    private function migration(): object
    {
        return require __DIR__.'/../database/migrations/shared/add_current_team_id_to_users_table.php.stub';
    }
}
