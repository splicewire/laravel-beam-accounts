<?php

use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Particle\Backing\ConfiguredUserBacking;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * particle-operation-surface 14 — the `users` resource must back the model the HOST configured, not a
 * class-string frozen into an attribute.
 *
 * `#[ParticleResource]` takes `backing:`, and an attribute argument must be a **constant expression** —
 * so `UserData` hardcoded `Splicewire\Beam\Accounts\Models\User::class` while the imperative `me`
 * resource (`WiresMeResource:68`) read `BeamAccounts::userModel()`. The host census and the reasoning
 * live on {@see ConfiguredUserBacking}; this file asserts the behaviour.
 *
 * ⚠️ This suite's own harness reproduces the defect exactly: `tests/Fixtures/User extends
 * Authenticatable`, not `Models\User`, and `TestCase:107` points `auth.providers.users.model` at it — so
 * these assertions run against the same shape the affected hosts have, rather than a contrived one.
 *
 * The failure is identity, not disclosure — the table is the same, but the concrete class decides
 * casts, global scopes, relations and policy binding.
 */
it('backs the users resource with the configured model, not a hardcoded class-string', function () {
    $backing = app(ParticleResourceRegistry::class)->get('users')->backing;

    expect($backing)->toBe(ConfiguredUserBacking::class);
});

it('resolves to the host-configured user model', function () {
    // ⚠️ Config is read in the BACKING'S CONSTRUCTOR and the backing is container-resolved at request
    // time, so this must be set BEFORE resolving. A test that resolves first passes against the defect.
    config()->set('beam.accounts.user_model', HostSuppliedUser::class);

    $resolved = app(BackingResolver::class)->resolve(ConfiguredUserBacking::class);

    expect($resolved)->toBeInstanceOf(BacksModel::class)
        ->and($resolved->modelClass())->toBe(HostSuppliedUser::class);
});

it('follows auth.providers.users.model when beam.accounts.user_model is null, as every host leaves it', function () {
    config()->set('beam.accounts.user_model', null);
    config()->set('auth.providers.users.model', HostSuppliedUser::class);

    expect(app(BackingResolver::class)->resolve(ConfiguredUserBacking::class)->modelClass())
        ->toBe(HostSuppliedUser::class)
        ->and(BeamAccounts::userModel())->toBe(HostSuppliedUser::class);
});

it('agrees with the me resource on which model a user is', function () {
    // The two declarations that disagreed, asserted at the harness's OWN configured model.
    //
    // ⚠️ Deliberately does NOT mutate config here, and the reason is a distinction the ticket first got
    // wrong: there are THREE freeze points, not two. `users` was attributed, so its class-string froze at
    // COMPILE time and could never follow config. `me` is imperative but calls `BeamAccounts::userModel()`
    // inside `register()` (`WiresMeResource:68`), so it freezes at BOOT. Only a `ResourceBacking` reaches
    // REQUEST time, because `BackingResolver` app()-resolves it. So setting config mid-test would move
    // `users` and leave the already-booted `me` behind — and the failure would look like this fix not
    // working, when it is the two freeze points being different by design.
    $resolver = app(BackingResolver::class);
    $registry = app(ParticleResourceRegistry::class);

    $users = $resolver->resolve($registry->get('users')->backing)->modelClass();
    $me = $resolver->resolve($registry->get('me')->backing)->modelClass();

    expect($users)->toBe($me)
        ->and($users)->toBe(BeamAccounts::userModel());
});

it('keeps every capability the EloquentBacking default carries', function () {
    // `hasCapability()` is decided STATICALLY off the class-string, so a future refactor of
    // EloquentBacking that dropped one of these would silently narrow this resource's affordances
    // rather than fail — assert the whole set, not just the one this ticket needed.
    //
    // ⚠️ The class_exists() guard is load-bearing, not defensive: `hasCapability()` treats any
    // class-string that is not a ResourceBacking as a MODEL and answers for `EloquentBacking`, which
    // carries all four. So a typo'd or deleted backing passes every assertion below vacuously. This test
    // passed against a ConfiguredUserBacking that did not exist yet.
    expect(class_exists(ConfiguredUserBacking::class))->toBeTrue();

    $resolver = app(BackingResolver::class);

    foreach ([BacksModel::class, QueriesRecords::class, StreamsRecords::class, WritesRecords::class] as $capability) {
        expect($resolver->hasCapability(ConfiguredUserBacking::class, $capability))
            ->toBeTrue("ConfiguredUserBacking lost {$capability}");
    }
});

class HostSuppliedUser extends Illuminate\Foundation\Auth\User {}
