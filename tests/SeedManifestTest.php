<?php

use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Database\Seeders\RolePermissionsSeeder;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Seed\BeamSeedManifest;

/*
 * beam-accounts registers its DemoTeamSeeder into beam-core's package-registered seed manifest
 * (splicewire:beam:seed), gated by `beam.accounts.demo.seed_users` so a production `beam:seed` never
 * fabricates demo subjects. The gate mirrors BeamDemo::enabled() — null resolves to non-production.
 */

it('registers the DemoTeamSeeder into the beam seed manifest, gated', function () {
    $steps = app(BeamSeedManifest::class)->steps();

    $step = collect($steps)->firstWhere('seeder', DemoTeamSeeder::class);

    expect($step)->not->toBeNull();
    expect($step->package)->toBe('splicewire/laravel-beam-accounts');
    expect($step->configGate)->toBe('beam.accounts.demo.seed_users');
});

it('resolves the seed gate to on in a non-production environment', function () {
    // The provider resolved the null default against the (testing, non-production) environment.
    expect(config('beam.accounts.demo.seed_users'))->toBeTrue();
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
