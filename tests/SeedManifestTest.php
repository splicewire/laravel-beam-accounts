<?php

use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
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
