<?php

namespace Splicewire\Beam\Accounts\Entitlements;

use LogicException;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;

/**
 * beam-accounts' default entitlement resolver (tutorial-dx 04): the host answers which plan, and the module does the rest.
 *
 *     realm defaults ({@see DefaultEntitlementResolver}) ∪ bundle(plan) ∪ every grant source's grants
 *
 * composed by {@see EntitlementComposer} (`plan-baseline ∪ grants − denies`). With no plan and no grants configured it
 * answers exactly what DefaultEntitlementResolver answers, so a host that configured nothing is unchanged.
 *
 * WHERE THE PLAN LIVES: one setting, `beam.accounts.entitlements.plan`, read with strict semantics:
 *   - null: no plan; no bundle is composed;
 *   - a class-string that exists: it MUST implement {@see PlanSource}, and is asked (a COMPUTED plan). A class that
 *     does not implement it is refused, never read as a path;
 *   - any other string: a `data_get` path on the principal (a STORED plan: an attribute, or a path through real
 *     relations).
 * A null or empty answer falls back to `beam.accounts.entitlements.plan_default` (null: no bundle).
 *
 * DERIVED GRANTS: `beam.accounts.entitlements.grants`, a list of {@see GrantSource} classes with static keys.
 *
 * A host that binds its own resolver (`permission-cascade.entitlement_resolver`) still wins, Tower's scope half
 * included; it can compose this one for its user half.
 */
class ModuleEntitlementResolver implements EntitlementResolver
{
    public function __construct(
        private EntitlementComposer $composer,
        private DefaultEntitlementResolver $defaults,
    ) {}

    public function entitlementsFor(mixed $principal): array
    {
        return $this->composer->composeForBundles(
            $this->plansFor($principal),
            grants: [
                ...array_values($this->defaults->entitlementsFor($principal)),
                ...$this->grantsFor($principal),
            ],
        );
    }

    /**
     * Every key a bundle or a grant source can produce: what beam-accounts contributes to beam's key universe.
     *
     * @return list<string>
     */
    public static function declaredKeys(): array
    {
        $bundles = app(BundleRegistry::class);
        $keys = $bundles->keysForMany($bundles->names());

        foreach (self::grantSources() as $source) {
            $keys = [...$keys, ...$source::keys()];
        }

        return array_values(array_unique($keys));
    }

    /** @return list<string> */
    protected function plansFor(mixed $principal): array
    {
        $setting = config('beam.accounts.entitlements.plan');

        if ($setting === null) {
            return [];
        }
        if (! is_string($setting) || $setting === '') {
            throw new LogicException('beam.accounts.entitlements.plan must be null, a data_get path or a '.PlanSource::class.' class name (tutorial-dx 04).');
        }

        if (class_exists($setting)) {
            if (! is_subclass_of($setting, PlanSource::class)) {
                throw new LogicException(sprintf(
                    'beam.accounts.entitlements.plan names the class [%s], which does not implement %s. A class there must implement '
                    .'%s (a computed plan); a stored plan is a data_get path instead (tutorial-dx 04).',
                    $setting, PlanSource::class, PlanSource::class,
                ));
            }
            $plan = app($setting)->planFor($principal);
        } else {
            $plan = data_get($principal, $setting);
        }

        $plans = array_values(array_filter((array) $plan, static fn ($name): bool => is_string($name) && $name !== ''));
        if ($plans !== []) {
            return $plans;
        }

        $default = config('beam.accounts.entitlements.plan_default');

        return is_string($default) && $default !== '' ? [$default] : [];
    }

    /** @return list<string> */
    protected function grantsFor(mixed $principal): array
    {
        $grants = [];

        foreach (self::grantSources() as $source) {
            $granted = array_values(app($source)->grantsFor($principal));
            $undeclared = array_diff($granted, $source::keys());

            if ($undeclared !== []) {
                throw new LogicException(sprintf(
                    'The grant source [%s] granted undeclared key(s) [%s]. Declare every key it can grant in its static keys(), '
                    .'so beam defines their abilities up front (tutorial-dx 04).',
                    $source, implode(', ', $undeclared),
                ));
            }

            $grants = [...$grants, ...$granted];
        }

        return $grants;
    }

    /** @return list<class-string<GrantSource>> */
    protected static function grantSources(): array
    {
        $sources = (array) config('beam.accounts.entitlements.grants', []);

        foreach ($sources as $source) {
            if (! is_string($source) || ! is_subclass_of($source, GrantSource::class)) {
                throw new LogicException(sprintf(
                    'beam.accounts.entitlements.grants lists [%s], which is not a %s class (tutorial-dx 04).',
                    is_string($source) ? $source : get_debug_type($source), GrantSource::class,
                ));
            }
        }

        return array_values($sources);
    }
}
