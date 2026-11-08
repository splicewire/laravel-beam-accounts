<?php

namespace Splicewire\Beam\Accounts\Tests;

use Illuminate\Support\Facades\Gate;
use LogicException;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\GrantSource;
use Splicewire\Beam\Accounts\Entitlements\ModuleEntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\PlanSource;
use Splicewire\Beam\Entitlements\EntitlementKeyUniverse;

/**
 * tutorial-dx 04 (orch-lead's ruling on orch-peer's design read): the host answers which plan; the module does the rest.
 *
 * Before: four hosts each hand-composed DefaultEntitlementResolver + EntitlementComposer::composeForBundles(<their plan>)
 * in a resolver of their own, and a bundle's keys reached the Gate only if the host also listed them in register().
 *
 * After: the module resolver is beam-accounts' default: realm defaults ∪ bundle(plan) ∪ grant sources. The host states
 * where a plan lives in ONE setting, `beam.accounts.entitlements.plan`, with strict semantics: a class implementing
 * PlanSource is asked (a computed plan); any other string is a `data_get` path on the principal (a stored plan); a class
 * that is not a PlanSource is refused, never read as a path. Derived grants are GrantSource classes with static keys,
 * so every key a bundle or grant can produce is known to beam's key universe up front.
 */
class ModuleEntitlementResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.accounts.entitlements.bundles', [
            'free' => ['calculators.basic'],
            'pro' => ['calculators.basic', 'calculators.profit'],
        ]);
    }

    private function resolve(mixed $principal): array
    {
        $keys = $this->app->make(EntitlementResolver::class)->entitlementsFor($principal);
        sort($keys);

        return $keys;
    }

    public function test_it_is_the_default_resolver_and_with_no_plan_or_grants_it_answers_what_the_defaults_answer(): void
    {
        $this->assertInstanceOf(ModuleEntitlementResolver::class, $this->app->make(EntitlementResolver::class));

        $principal = new class {};
        $this->assertSame(
            $this->app->make(DefaultEntitlementResolver::class)->entitlementsFor($principal),
            $this->resolve($principal),
        );
    }

    public function test_a_stored_plan_is_read_by_a_data_get_path(): void
    {
        config(['beam.accounts.entitlements.plan' => 'plan_tier']);

        $this->assertSame(['calculators.basic', 'calculators.profit'], $this->resolve(new class { public $plan_tier = 'pro'; }));
    }

    public function test_an_empty_stored_plan_falls_back_to_plan_default_and_without_one_composes_no_bundle(): void
    {
        config(['beam.accounts.entitlements.plan' => 'plan_tier']);
        $none = new class { public $plan_tier = null; };

        $this->assertSame([], $this->resolve($none));

        config(['beam.accounts.entitlements.plan_default' => 'free']);
        $this->assertSame(['calculators.basic'], $this->resolve($none));
    }

    public function test_a_computed_plan_comes_from_a_plan_source(): void
    {
        config(['beam.accounts.entitlements.plan' => TeamPlanSource::class]);

        $this->assertSame(['calculators.basic', 'calculators.profit'], $this->resolve(new class { public $team = 'paid'; }));
    }

    public function test_a_class_that_is_not_a_plan_source_is_refused_not_read_as_a_path(): void
    {
        config(['beam.accounts.entitlements.plan' => NotAPlanSource::class]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('implement '.PlanSource::class);
        $this->resolve(new class {});
    }

    public function test_a_grant_source_adds_its_derived_keys(): void
    {
        config(['beam.accounts.entitlements.grants' => [OperatorBilling::class]]);

        $this->assertSame(['billing.manage'], $this->resolve(new class { public $operator = true; }));
        $this->assertSame([], $this->resolve(new class { public $operator = false; }));
    }

    public function test_a_grant_source_that_grants_an_undeclared_key_is_refused(): void
    {
        config(['beam.accounts.entitlements.grants' => [LeakyGrant::class]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('undeclared');
        $this->resolve(new class {});
    }

    public function test_every_bundle_and_grant_key_is_known_to_beam_s_key_universe_and_its_ability_is_defined(): void
    {
        config(['beam.accounts.entitlements.grants' => [OperatorBilling::class]]);
        $keys = $this->app->make(EntitlementKeyUniverse::class)->keys();

        foreach (['calculators.basic', 'calculators.profit', 'billing.manage'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertTrue(Gate::has('entitlement:calculators.profit'), 'a bundle key needs no register()-time listing by the host');
    }
}

class TeamPlanSource implements PlanSource
{
    public function planFor(mixed $principal): string|array|null
    {
        return ($principal->team ?? null) === 'paid' ? 'pro' : null;
    }
}

class NotAPlanSource {}

class OperatorBilling implements GrantSource
{
    public static function keys(): array
    {
        return ['billing.manage'];
    }

    public function grantsFor(mixed $principal): array
    {
        return ($principal->operator ?? false) ? ['billing.manage'] : [];
    }
}

class LeakyGrant implements GrantSource
{
    public static function keys(): array
    {
        return ['declared.key'];
    }

    public function grantsFor(mixed $principal): array
    {
        return ['declared.key', 'sneaky.key'];
    }
}
