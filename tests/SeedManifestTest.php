<?php

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Seed\SeedStep;

/*
 * beam-accounts registers its DemoTeamSeeder into beam-core's package-registered seed manifest
 * (splicewire:beam:seed) as a `demo` step, so the run's Seeding Scenario decides where demo subjects
 * are fabricated (seeding-scenarios 01). `beam.accounts.demo.seed_users` is only the host's opt-out,
 * AND-ed with the teams estate; it no longer reads the environment.
 */

it('registers the DemoTeamSeeder into the beam seed manifest as a demo step, gated', function () {
    $steps = app(BeamSeedManifest::class)->steps();

    $step = collect($steps)->firstWhere('seeder', DemoTeamSeeder::class);

    expect($step)->not->toBeNull();
    expect($step->package)->toBe('splicewire/laravel-beam-accounts');
    expect($step->configGate)->toBe('beam.accounts.demo.seed_users');
    expect($step->memberships)->toBe([SeedStep::DEMO]);
});

it('leaves the seed gate open with no host opinion, in production too: the environment is not read', function () {
    expect(config('beam.accounts.demo.seed_users'))->toBeTrue();

    app()['env'] = 'production';
    config(['beam.accounts.demo.seed_users' => null]);
    app()->register(new BeamAccountsServiceProvider(app()), force: true);

    expect(config('beam.accounts.demo.seed_users'))->toBeTrue();
});

it('runs the demo step under the local scenario and leaves it out under baseline, in production', function () {
    app()['env'] = 'production';

    $this->artisan('splicewire:beam:seed', ['--force' => true])
        ->expectsOutputToContain('scenario baseline (selected by baseline)')
        ->expectsOutputToContain('splicewire/laravel-beam-accounts: left out by scenario baseline (does not include demo)')
        ->assertExitCode(0);

    expect(DB::table('beam_teams')->count())->toBe(0);

    // The scenario decides what seeds; the demo login door (BeamDemo::enabled(), shut by default in production)
    // decides only who can log in, on the login path, so the team is seeded with the door shut.
    expect(BeamDemo::enabled())->toBeFalse();

    $this->artisan('splicewire:beam:seed', ['--force' => true, '--scenario' => 'local'])
        ->expectsOutputToContain('scenario local (selected by option)')
        ->expectsOutputToContain('splicewire/laravel-beam-accounts: '.DemoTeamSeeder::class)
        ->assertExitCode(0);

    expect(DB::table('beam_teams')->count())->toBeGreaterThan(0);
});

it('registers the role-permission backfill as a baseline step', function () {
    $step = collect(app(BeamSeedManifest::class)->steps())->firstWhere('seeder', RolePermissionsSeeder::class);

    expect($step->memberships)->toBe([SeedStep::BASELINE]);
});

it('registers the ungated RolePermissionsSeeder under its own manifest key', function () {
    $steps = app(BeamSeedManifest::class)->steps();

    $step = collect($steps)->firstWhere('seeder', RolePermissionsSeeder::class);

    expect($step)->not->toBeNull();
    expect($step->package)->toBe('splicewire/laravel-beam-accounts/role-permissions');
    // Ungated on purpose: this repairs authorization for rows a host already has; a production
    // seed wants it. Gating it behind the demo key would mean only demo hosts got a working nav.
    expect($step->configGate)->toBeNull();
    expect($step->order)->toBeLessThan(collect($steps)->firstWhere('seeder', DemoTeamSeeder::class)->order);
});

it('keeps BOTH beam-accounts steps — a shared key would supersede one', function () {
    $seeders = collect(app(BeamSeedManifest::class)->steps())->pluck('seeder');

    expect($seeders)->toContain(DemoTeamSeeder::class)
        ->and($seeders)->toContain(RolePermissionsSeeder::class);
});
