<?php

namespace Splicewire\Beam\Accounts\Flows\Gates;

use Laravel\Fortify\Features;
use Splicewire\Beam\Accounts\Http\Controllers\SecurityController;
use Splicewire\Beam\Flows\FlowGate;
use Splicewire\Beam\Flows\FlowState;

/**
 * The `account.security.two-factor` switch (browser-journeys 02, R3-Q1): the same source
 * {@see SecurityController} reads. `Absent` without Fortify;
 * else `On`/`Off` from `Features::canManageTwoFactorAuthentication()`. Never throws.
 */
final class TwoFactorGate implements FlowGate
{
    public function state(): FlowState
    {
        if (! class_exists(Features::class)) {
            return FlowState::Absent;
        }

        return Features::canManageTwoFactorAuthentication() ? FlowState::On : FlowState::Off;
    }
}
