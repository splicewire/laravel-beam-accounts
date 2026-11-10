<?php

/*
 * Boots this package's DEFAULT test composition in a fresh PHP process: its own TestCase, set up exactly as every default
 * test is (its providers, its environment, its schema), laravel-beam-analytics absent from it. Reports which classes that
 * boot loaded. Run by AudienceAreaAbsentTest: a fresh process is the only honest answer to "did booting load this class",
 * since the suite's own process loads them elsewhere on purpose.
 *
 *   php boot-accounts-alone.php <package root>     prints one JSON object
 */

require $argv[1].'/vendor/autoload.php';

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Tests\TestCase;

$case = new class('boot-accounts-alone') extends TestCase
{
    public function boot(): Illuminate\Foundation\Application
    {
        $this->setUp();

        return $this->app;
    }
};

$app = $case->boot();

$classes = [
    'Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry',
    'Splicewire\Beam\Analytics\Areas\AnalyticsArea',
    'Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider',
    'Splicewire\Beam\Accounts\Analytics\AudienceArea',
    'Splicewire\Beam\Accounts\Analytics\AudienceDestinations',
];
// Read before the installed-check below, which autoloads the analytics provider on purpose.
$loaded = array_values(array_filter($classes, fn (string $class): bool => class_exists($class, false) || interface_exists($class, false)));
$resolved = $app->resolved('Splicewire\Beam\Analytics\Areas\AnalyticsAreaRegistry');

echo json_encode([
    'booted' => $app->providerIsLoaded(BeamAccountsServiceProvider::class),
    'loaded' => $loaded,
    'registryResolved' => $resolved,
    'analyticsInstalled' => class_exists('Splicewire\Beam\Analytics\BeamAnalyticsServiceProvider'),
]);
