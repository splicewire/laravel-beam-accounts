<?php

namespace Splicewire\Beam\Accounts\Flows\Gates;

use Laravel\Fortify\Features;
use Splicewire\Beam\Flows\FlowGate;
use Splicewire\Beam\Flows\FlowState;

/**
 * The `auth.register` switch (browser-journeys 02, R3-Q1). It reads the same source the `/register`
 * route reads: `Absent` unless Fortify is installed and this host bootstraps it, then `On`/`Off` from
 * Fortify's registration feature. The `class_exists` guard comes first so the gate never throws at a
 * host without Fortify.
 */
final class RegistrationGate implements FlowGate
{
    public function state(): FlowState
    {
        if (! class_exists(Features::class) || ! config('beam.accounts.bootstrap_fortify', true)) {
            return FlowState::Absent;
        }

        return Features::enabled(Features::registration()) ? FlowState::On : FlowState::Off;
    }
}
