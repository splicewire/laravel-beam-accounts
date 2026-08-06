<?php

namespace Splicewire\Beam\Accounts\Entitlements;

/**
 * Frame OS ticket 09 (ADR-0013 §3/§5): the override algebra over a bundle baseline.
 *
 * A principal's effective entitlement set is the canonical
 *
 *     plan-baseline ∪ explicit-grants − denies
 *
 * where the **baseline** is whatever the principal's plan-bundle(s) declare (via the {@see BundleRegistry}),
 * **grants** are per-principal comp/admin additions (a support grant, a beta flag) that union in, and
 * **denies** subtract out (a revoked capability, a suspended feature) and win over both baseline and grants.
 * The result is a deduped, order-insensitive set.
 *
 * This is a domain-neutral set operation — it knows nothing about how a host discovers a principal's plan,
 * its grants, or its denies. A host's {@see \Rushing\PermissionCascade\Contracts\EntitlementResolver}
 * gathers those three inputs (from its own plan/grant model) and calls {@see compose()} to fold them.
 *
 * ## Composing with the commerce cascade
 *
 * The platform's multi-tenant commerce path (`Splicewire\Beam\Commerce\Entitlements\EntitlementResolver`)
 * resolves a tenant's set through `global-default → Plan::entitlementDefaults() → per-tenant override`. That
 * cascade IS this same algebra expressed per-capability over a boolean map: a plan's `entitlementDefaults()`
 * is its baseline (a `{key: true}` set = a bundle-by-value), and a per-tenant override with `true`/`false`
 * is exactly a grant/deny. This composer does NOT replace that resolver — the tenant principal keeps the
 * commerce cascade. It is the parallel, *host-principal* path: a consumer host (audiostud, where the
 * principal is a USER, not a tenant) declares NAMED bundles here, maps its plan to a bundle name, and folds
 * user-level grants/denies with {@see compose()}. Same algebra, two homes: commerce owns the tenant
 * cascade; this owns the named-bundle host path. A host binds ONE resolver for its principal shape.
 */
class EntitlementComposer
{
    public function __construct(private BundleRegistry $bundles) {}

    /**
     * Fold a baseline of entitlement keys with explicit grants and denies.
     *
     * @param  list<string>  $baseline  the plan-bundle keys (already resolved, e.g. via {@see bundleKeys()})
     * @param  list<string>  $grants    comp/admin keys that union in
     * @param  list<string>  $denies    keys that subtract out (win over baseline AND grants)
     * @return list<string> the deduped effective key set
     */
    public function compose(array $baseline, array $grants = [], array $denies = []): array
    {
        $union = array_values(array_unique([...$baseline, ...$grants]));

        $denySet = array_flip($denies);

        return array_values(array_filter(
            $union,
            fn (string $key): bool => ! isset($denySet[$key])
        ));
    }

    /**
     * Convenience: resolve a plan's bundle name(s) to their baseline keys, then compose with grants/denies.
     * The common host call — a plan maps to one (or several) named bundle(s), overlaid with per-principal
     * grants/denies.
     *
     * @param  string|list<string>  $bundleNames  the plan's bundle name, or several
     * @param  list<string>  $grants
     * @param  list<string>  $denies
     * @return list<string>
     */
    public function composeForBundles(string|array $bundleNames, array $grants = [], array $denies = []): array
    {
        $names = is_string($bundleNames) ? [$bundleNames] : $bundleNames;

        return $this->compose($this->bundles->keysForMany($names), $grants, $denies);
    }
}
