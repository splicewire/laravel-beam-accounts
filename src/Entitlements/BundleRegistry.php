<?php

namespace Splicewire\Beam\Accounts\Entitlements;

/**
 * Frame OS ticket 09 (ADR-0013 §3/§5): the declarative named-bundle registry.
 *
 * A **bundle** is a named `Set<entitlementKey>` — the reusable, product-facing unit a plan maps to (the
 * common case: a plan grants exactly one bundle's keys). Bundles are declared in
 * `config('beam.accounts.entitlements.bundles')` as `name => list<string>`; a host declares its consumer
 * bundles (`own-a-song`, `go-songwriter`, a `staff` bundle, …) there and never re-derives them.
 *
 * This registry is pure lookup — no plan model, no override algebra. The {@see EntitlementComposer} layers
 * grants/denies over the baseline a bundle (or several) supplies. Keeping the two apart means the naming of
 * capability sets (this class) is independent of the set algebra (the composer), and either can be tested in
 * isolation.
 *
 * Inert by default: with no `bundles` config every bundle resolves to the empty set, so an unconfigured host
 * holds nothing (the null-default discipline, ADR-0009).
 */
class BundleRegistry
{
    /**
     * @param  array<string, list<string>>  $bundles  name → entitlement keys. Defaults to the host config.
     */
    public function __construct(private array $bundles = [])
    {
        if ($bundles === []) {
            $this->bundles = (array) config('beam.accounts.entitlements.bundles', []);
        }
    }

    /**
     * The deduped entitlement-key set a named bundle declares. An unknown bundle → the empty set (a plan may
     * map to a tier that declares no bundle yet).
     *
     * @return list<string>
     */
    public function keys(string $bundle): array
    {
        return array_values(array_unique(array_map(
            'strval',
            (array) ($this->bundles[$bundle] ?? [])
        )));
    }

    /**
     * The union of several bundles' keys (a principal on a plan that grants more than one bundle, or a plan
     * whose baseline is itself several bundles), deduped.
     *
     * @param  list<string>  $bundles
     * @return list<string>
     */
    public function keysForMany(array $bundles): array
    {
        $keys = [];
        foreach ($bundles as $bundle) {
            $keys = [...$keys, ...$this->keys($bundle)];
        }

        return array_values(array_unique($keys));
    }

    /** Whether a bundle name is declared. */
    public function has(string $bundle): bool
    {
        return array_key_exists($bundle, $this->bundles);
    }

    /** @return list<string> every declared bundle name */
    public function names(): array
    {
        return array_keys($this->bundles);
    }
}
