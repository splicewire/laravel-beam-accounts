<?php

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Accounts\Doctor\DemoSeedReachabilityAudit;

/**
 * The demo login door and the demo-subject seed step are gated independently, so a host can reach a
 * state where sign-in is ON and nothing was ever seeded — an empty roster behind an open door, which
 * is silent. This audit makes that state visible. Ticket 29's "must not stay SILENT" box.
 *
 * The audit reads config live (no boot needed): `beam.accounts.demo.enabled` (the door), the
 * EFFECTIVE `beam.accounts.demo.seed_users` that {@see WiresSeed} resolves in boot, and the teams
 * estate declaration. These tests set those three directly to stand each host shape up.
 */
function demoSeedReachability(): array
{
    return (new DemoSeedReachabilityAudit)->run();
}

it('passes when the demo door is off — seeding is moot there', function () {
    config(['beam.accounts.demo.enabled' => false]);

    $findings = demoSeedReachability();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Pass);
});

it('passes when the door is on and the subjects seed', function () {
    config([
        'beam.accounts.demo.enabled' => true,
        'beam.accounts.demo.seed_users' => true,
    ]);

    $findings = demoSeedReachability();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Pass);
});

it('warns, and names the safe remedy, when a teams-absent host has the door on but no subjects seeded', function () {
    config([
        'beam.accounts.demo.enabled' => true,
        'beam.accounts.publish_migrations' => 'absent',
        'beam.accounts.demo.seed_users' => false,
    ]);

    $findings = demoSeedReachability();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warn)
        ->and($findings[0]->detail)->toContain('ACCOUNT_SEED_DEMO_USERS=true')
        ->and($findings[0]->detail)->toContain('EMPTY');
});

it('warns when the door is on but the host turned seeding off itself, even with the estate present', function () {
    config([
        'beam.accounts.demo.enabled' => true,
        'beam.accounts.publish_migrations' => true,
        'beam.accounts.demo.seed_users' => false,
    ]);

    $findings = demoSeedReachability();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Warn);
});
