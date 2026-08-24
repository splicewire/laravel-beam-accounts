<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * Exposes `registerCentralConnectionAlias()` so its guard branches can be driven against a config
 * state the test controls.
 *
 * Needed because of a Testbench ordering artifact, NOT a property of the code under test: the real
 * `register()` phase reads a fully-loaded config (Laravel loads every config file before the first
 * provider registers), whereas Testbench applies `defineEnvironment()` AFTER package providers have
 * registered — so the wired alias in a Testbench app is always a copy of Testbench's own default
 * `testing` block, and a test that sets `database.*` in `defineEnvironment` can never observe the
 * guard it thinks it is exercising. {@see \Splicewire\Beam\Accounts\Tests\CentralConnectionAliasTest}
 * asserts the wiring itself; this probe asserts the rules.
 */
class AliasProbeProvider extends BeamAccountsServiceProvider
{
    public function probeCentralConnectionAlias(): void
    {
        $this->registerCentralConnectionAlias();
    }
}
