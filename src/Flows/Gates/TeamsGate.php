<?php

namespace Splicewire\Beam\Accounts\Flows\Gates;

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Flows\FlowGate;
use Splicewire\Beam\Flows\FlowState;

/**
 * The `teams` / `teams.team-of-one` switch (browser-journeys 02, R3-Q1): `Absent` when the host has
 * declared the teams estate absent (the flagship does), else `On`. Reads the package's own
 * `estateDeclaredAbsent()`, so a host that ships no teams migrations is structurally absent, not a
 * Deviation. Never throws.
 */
final class TeamsGate implements FlowGate
{
    public function state(): FlowState
    {
        return BeamAccountsServiceProvider::estateDeclaredAbsent('migrations')
            ? FlowState::Absent
            : FlowState::On;
    }
}
