<?php

namespace Splicewire\Beam\Accounts\Flows;

use Splicewire\Beam\Accounts\Flows\Gates\ForgotPasswordGate;
use Splicewire\Beam\Accounts\Flows\Gates\PasskeysGate;
use Splicewire\Beam\Accounts\Flows\Gates\RegistrationGate;
use Splicewire\Beam\Accounts\Flows\Gates\TeamsGate;
use Splicewire\Beam\Accounts\Flows\Gates\TwoFactorGate;
use Splicewire\Beam\Flows\DeclaresFlows;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Flows\ListsFlows;

/**
 * beam-accounts' flow declarations (browser-journeys 08): the `auth`, `account` and `teams` surfaces
 * and every flow its browser suite proves, plus the finer security flows its runtime already switches
 * (two-factor, passkeys) and the registration/reset gates.
 *
 * Pure and static: {@see flows()} builds declarations with `new`, naming gate class-strings, so it is
 * safe to call before Laravel boots. `BeamAccountsServiceProvider` declares this class to the catalog
 * inside its registrant scope; checkpoints are `null` (ticket 04's Checkpoint contract lands with
 * ticket 16, which inherits the roles-subset-of-checkpoint-roles check).
 */
final class AccountFlows implements DeclaresFlows
{
    use ListsFlows;

    public static function flows(): array
    {
        return [
            // Account access (Fortify-served; guest flows, no demo-subject role).
            new FlowDeclaration('auth', 'Account access', [], AlwaysOn::class, null, []),
            // browser-journeys 10 adds auth.login: 08's deviation-focused logic skipped it (login has no
            // manifest_deviates, so no Deviation to switch), but 10 requires every shipped browser test to name
            // its flow — the login page is the auth surface's always-available entry. AlwaysOn, guest (no role).
            new FlowDeclaration('auth.login', 'Sign in', ['login'], AlwaysOn::class, null, []),
            new FlowDeclaration('auth.register', 'Registration', ['register'], RegistrationGate::class, null, []),
            new FlowDeclaration('auth.forgot-password', 'Password reset', ['password.request'], ForgotPasswordGate::class, null, []),

            // Account settings (verified for every demo subject).
            new FlowDeclaration('account', 'Account', [], AlwaysOn::class, null, []),
            new FlowDeclaration('account.profile', 'Profile', ['profile.edit'], AlwaysOn::class, null, ['owner', 'admin', 'member', 'solo']),
            new FlowDeclaration('account.security', 'Security', ['security.edit'], AlwaysOn::class, null, ['owner', 'admin', 'member', 'solo']),
            new FlowDeclaration('account.security.two-factor', 'Two-factor authentication', ['security.edit'], TwoFactorGate::class, null, ['owner', 'admin', 'member', 'solo']),
            new FlowDeclaration('account.security.passkeys', 'Passkeys', ['security.edit'], PasskeysGate::class, null, ['owner', 'admin', 'member', 'solo']),

            // Teams (structurally absent at a host that ships no teams estate).
            new FlowDeclaration('teams', 'Teams', [], TeamsGate::class, null, []),
            new FlowDeclaration('teams.team-of-one', 'Personal team', [], TeamsGate::class, null, []),
        ];
    }
}
