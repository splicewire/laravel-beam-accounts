<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\BeamManager;

class TeamSlugRollbackTest extends TestCase
{
    public static function prefixes(): array
    {
        return [['beam_'], ['host_']];
    }

    #[DataProvider('prefixes')]
    public function test_shipped_slug_migration_rolls_back_on_sqlite_without_losing_team_rows(string $prefix): void
    {
        $previousContainer = Container::getInstance();
        $previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository(['beam' => ['core' => ['table_prefix' => $prefix]]]));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $database = new Capsule($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $container->instance('db', $database->getDatabaseManager());
        $container->bind('db.schema', fn () => $database->getConnection()->getSchemaBuilder());
        $container->instance(BeamManager::class, new BeamManager($container));

        try {
            $teams = $prefix.'teams';
            $schema = $database->getConnection()->getSchemaBuilder();
            $schema->create($teams, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
            $database->getConnection()->table($teams)->insert(['name' => 'Acme Support']);
            $before = $database->getConnection()->table($teams)->get()->map(fn (object $row): array => (array) $row)->all();
            $migration = require __DIR__.'/../database/migrations/shared/add_slug_to_teams_table.php.stub';

            $migration->up();
            self::assertSame('acme-support', $database->getConnection()->table($teams)->value('slug'));
            self::assertTrue(collect($schema->getIndexes($teams))->contains(
                fn (array $index): bool => $index['unique'] && $index['columns'] === ['slug'],
            ));

            $migration->down();
            self::assertFalse($schema->hasColumn($teams, 'slug'));
            self::assertSame($before, $database->getConnection()->table($teams)->get()->map(fn (object $row): array => (array) $row)->all());
            $migration->down();
            self::assertSame($before, $database->getConnection()->table($teams)->get()->map(fn (object $row): array => (array) $row)->all());
        } finally {
            $database->getDatabaseManager()->disconnect();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacadeApplication);
            Container::setInstance($previousContainer);
        }
    }
}
