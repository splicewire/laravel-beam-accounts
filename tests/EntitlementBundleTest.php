<?php

namespace Splicewire\Beam\Accounts\Tests;

use Splicewire\Beam\Accounts\Entitlements\BundleRegistry;
use Splicewire\Beam\Accounts\Entitlements\EntitlementComposer;

/**
 * Frame OS ticket 09: the declarative bundle mapping + the `plan-baseline ∪ grants − denies` override
 * algebra (BundleRegistry + EntitlementComposer). Boots the full beam-accounts TestCase (the suite is
 * green — no popcorn drift), so config-backed resolution is exercised as the host sees it.
 */
class EntitlementBundleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.accounts.entitlements.bundles', [
            'own-a-song' => ['own-a-song', 'publish'],
            'go-songwriter' => ['own-a-song', 'go-songwriter', 'publish', 'generate'],
            'staff' => ['author-ux', 'workbench.enter'],
            // A bundle with a duplicate key, to prove dedupe at declaration.
            'dupey' => ['a', 'a', 'b'],
        ]);
    }

    public function test_a_plan_bundle_resolves_to_its_declared_key_set(): void
    {
        $registry = $this->app->make(BundleRegistry::class);

        $this->assertSame(['own-a-song', 'publish'], $registry->keysFor('own-a-song'));
        $this->assertSame(
            ['own-a-song', 'go-songwriter', 'publish', 'generate'],
            $registry->keysFor('go-songwriter')
        );
        $this->assertSame(['author-ux', 'workbench.enter'], $registry->keysFor('staff'));
    }

    public function test_an_unknown_bundle_resolves_to_the_empty_set(): void
    {
        $registry = $this->app->make(BundleRegistry::class);

        $this->assertSame([], $registry->keysFor('no-such-bundle'));
        $this->assertFalse($registry->has('no-such-bundle'));
        $this->assertTrue($registry->has('staff'));
    }

    public function test_declaration_dedupes_keys_within_a_bundle(): void
    {
        $registry = $this->app->make(BundleRegistry::class);

        $this->assertSame(['a', 'b'], $registry->keysFor('dupey'));
    }

    public function test_keys_for_many_unions_and_dedupes_across_bundles(): void
    {
        $registry = $this->app->make(BundleRegistry::class);

        $this->assertSame(
            ['own-a-song', 'publish', 'go-songwriter', 'generate'],
            $registry->keysForMany(['own-a-song', 'go-songwriter'])
        );
    }

    public function test_compose_returns_the_baseline_when_no_overrides(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        $this->assertSame(
            ['own-a-song', 'publish'],
            $composer->compose(['own-a-song', 'publish'])
        );
    }

    public function test_grants_union_into_the_baseline(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        $this->assertSame(
            ['own-a-song', 'publish', 'beta-flag'],
            $composer->compose(['own-a-song', 'publish'], grants: ['beta-flag'])
        );
    }

    public function test_a_grant_already_in_the_baseline_dedupes(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        $this->assertSame(
            ['own-a-song', 'publish'],
            $composer->compose(['own-a-song', 'publish'], grants: ['publish'])
        );
    }

    public function test_denies_subtract_from_the_baseline(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        $this->assertSame(
            ['own-a-song'],
            $composer->compose(['own-a-song', 'publish'], denies: ['publish'])
        );
    }

    public function test_a_deny_wins_over_a_grant_of_the_same_key(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        // Grant and deny the same key → the deny wins (subtraction is applied last).
        $this->assertSame(
            ['own-a-song'],
            $composer->compose(['own-a-song'], grants: ['publish'], denies: ['publish'])
        );
    }

    public function test_the_full_algebra_baseline_union_grants_minus_denies(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        $this->assertSame(
            ['own-a-song', 'go-songwriter', 'comp-extra'],
            $composer->compose(
                baseline: ['own-a-song', 'go-songwriter', 'publish'],
                grants: ['comp-extra', 'go-songwriter'], // go-songwriter already present → deduped
                denies: ['publish'],
            )
        );
    }

    public function test_compose_for_bundles_resolves_names_then_folds_overrides(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        // go-songwriter baseline minus `generate`, plus a comp key.
        $this->assertSame(
            ['own-a-song', 'go-songwriter', 'publish', 'comp'],
            $composer->composeForBundles('go-songwriter', grants: ['comp'], denies: ['generate'])
        );
    }

    public function test_compose_for_bundles_accepts_several_bundle_names(): void
    {
        $composer = $this->app->make(EntitlementComposer::class);

        $this->assertSame(
            ['own-a-song', 'publish', 'author-ux', 'workbench.enter'],
            $composer->composeForBundles(['own-a-song', 'staff'])
        );
    }
}
