<?php

use Laravel\Fortify\Features;
use Splicewire\Beam\Accounts\Flows\AccountFlows;
use Splicewire\Beam\Accounts\Flows\Gates\ForgotPasswordGate;
use Splicewire\Beam\Accounts\Flows\Gates\PasskeysGate;
use Splicewire\Beam\Accounts\Flows\Gates\RegistrationGate;
use Splicewire\Beam\Accounts\Flows\Gates\TeamsGate;
use Splicewire\Beam\Accounts\Flows\Gates\TwoFactorGate;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\FlowState;

// ── the declaration class (static, boot-free) ───────────────────────────────────────────────────

it('declares every key its browser suite proves, plus the finer security flows and the surfaces', function () {
    $keys = array_map(fn ($flow) => $flow->key, AccountFlows::flows());

    expect($keys)->toContain(
        // surfaces
        'auth', 'account', 'teams',
        // keys the shipped suites name
        'account.profile', 'account.security', 'auth.register', 'auth.forgot-password', 'teams.team-of-one',
        'teams.invitation-accept',
        // the finer runtime-switched flows + the registration gate
        'account.security.two-factor', 'account.security.passkeys',
    );
});

it('answers flow($key)->roles statically, with no booted app', function () {
    expect(AccountFlows::flow('account.security')->roles)->toBe(['owner', 'admin', 'member', 'solo'])
        ->and(AccountFlows::flow('auth.register')->roles)->toBe([]);
});

// ── the provider registers it into beam.flows under its registrant scope ─────────────────────────

it('registers AccountFlows into the beam.flows catalog, keyed and attributed to this package', function () {
    $catalog = app(FlowCatalog::class);

    expect($catalog->has('account.security.passkeys'))->toBeTrue()
        ->and($catalog->registrantOf('account.security.passkeys'))->toBe('splicewire/laravel-beam-accounts')
        ->and($catalog->declarations('account.security.passkeys')[0]->gate)->toBe(PasskeysGate::class)
        ->and($catalog->declarations('auth.register')[0]->gate)->toBe(RegistrationGate::class);
});

// ── the gates answer the host switch, tri-state, never throwing ──────────────────────────────────

it('gates teams on the declared estate', function () {
    expect(app(TeamsGate::class)->state())->toBe(FlowState::On);

    config(['beam.accounts.register_migrations' => 'absent']);
    expect(app(TeamsGate::class)->state())->toBe(FlowState::Absent);
});

it('gates registration on Fortify bootstrap and its registration feature', function () {
    // Fortify wired (default) + registration enabled (default feature list) ⇒ On.
    expect(app(RegistrationGate::class)->state())->toBe(FlowState::On);

    // Fortify wired, registration feature removed ⇒ Off.
    config(['fortify.features' => [Features::resetPasswords()]]);
    expect(app(RegistrationGate::class)->state())->toBe(FlowState::Off);

    // Fortify not bootstrapped by this host ⇒ Absent (the gate short-circuits before touching Fortify).
    config(['beam.accounts.bootstrap_fortify' => false]);
    expect(app(RegistrationGate::class)->state())->toBe(FlowState::Absent);
});

it('gates password reset on Fortify bootstrap and its reset feature', function () {
    expect(app(ForgotPasswordGate::class)->state())->toBe(FlowState::On);

    config(['fortify.features' => [Features::registration()]]);
    expect(app(ForgotPasswordGate::class)->state())->toBe(FlowState::Off);

    config(['beam.accounts.bootstrap_fortify' => false]);
    expect(app(ForgotPasswordGate::class)->state())->toBe(FlowState::Absent);
});

it('gates two-factor and passkeys on their Fortify features', function () {
    // Neither feature is in the harness default ⇒ Off.
    expect(app(TwoFactorGate::class)->state())->toBe(FlowState::Off)
        ->and(app(PasskeysGate::class)->state())->toBe(FlowState::Off);

    config(['fortify.features' => [Features::twoFactorAuthentication(), Features::passkeys()]]);
    expect(app(TwoFactorGate::class)->state())->toBe(FlowState::On)
        ->and(app(PasskeysGate::class)->state())->toBe(FlowState::On);
});
