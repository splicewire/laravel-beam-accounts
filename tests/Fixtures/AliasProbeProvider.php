<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Splicewire\Beam\BeamServiceProvider;

/**
 * Exposes beam-CORE's `registerCentralConnectionAlias()` so it can be re-run against a config state
 * this package's test controls. Extends `BeamServiceProvider` since beam-facade ticket 96 moved the
 * alias down a tier; it extended `BeamAccountsServiceProvider` while the alias lived here.
 *
 * Needed because of a Testbench ordering artifact, NOT a property of the code under test: the real
 * `register()` phase reads a fully-loaded config (Laravel loads every config file before the first
 * provider registers), whereas Testbench applies `defineEnvironment()` AFTER package providers have
 * registered — so the wired alias in a Testbench app is a copy of Testbench's own default `:memory:`
 * block, and a read test that never re-runs the alias queries a second, empty database rather than
 * the file the test wrote to. {@see \Splicewire\Beam\Accounts\Tests\CentralConnectionAliasTest}
 * asserts that core wired the alias at all; this probe realigns it before the read.
 */
class AliasProbeProvider extends BeamServiceProvider
{
    public function probeCentralConnectionAlias(): void
    {
        $this->registerCentralConnectionAlias();
    }
}
