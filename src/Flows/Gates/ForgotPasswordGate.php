<?php

namespace Splicewire\Beam\Accounts\Flows\Gates;

use Laravel\Fortify\Features;
use Splicewire\Beam\Flows\FlowGate;
use Splicewire\Beam\Flows\FlowState;

/**
 * The `auth.forgot-password` switch (browser-journeys 02, R3-Q1): the same Fortify-bootstrap guard as
 * {@see RegistrationGate}, then `On`/`Off` from Fortify's reset-passwords feature. Never throws.
 */
final class ForgotPasswordGate implements FlowGate
{
    public function state(): FlowState
    {
        if (! class_exists(Features::class) || ! config('beam.accounts.bootstrap_fortify', true)) {
            return FlowState::Absent;
        }

        return Features::enabled(Features::resetPasswords()) ? FlowState::On : FlowState::Off;
    }
}
