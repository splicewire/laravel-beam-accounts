<?php

namespace Splicewire\Beam\Accounts\Entitlements;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;
use Rushing\Popcorn\Registries\RegistryKey;

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
 *
 * ## On the popcorn kernel (registry-kernel ticket 38)
 *
 * This is archetype **b** in the true sense the sweep's amendment A7 asks for: the config array is not a
 * seed that some private array then owns — it **is** the storage, and nothing else ever writes. So the
 * class becomes a {@see ConfigRegistry} subclass and the array stays exactly where it was.
 *
 * That also retires a latent snapshot bug the sweep's amendment A8 names. The old constructor read
 * `config(...)` once; bound as a singleton, it froze the bundle map at first resolution, ahead of any host
 * or test that appended a bundle later. `ConfigRegistry` reads through to the repository on every read, so
 * a late-declared bundle is simply visible.
 *
 * ⚠️ **`keys()` was RENAMED to {@see keysFor()}.** The kernel's contract spells `keys(): array` — *every
 * key in this registry* — and this class had spent that name on *the entitlement keys OF one bundle*, which
 * is `resolve()`. PHP cannot hold both, so the port's own accessor takes the name that says what it does.
 * `keysForMany()`, `has()` and `names()` are unchanged.
 */
#[IsRegistry(
    root: 'beam.accounts.entitlements.bundles',
    entryType: 'list<string>',
    onKeyDuplicate: OnKeyDuplicate::Supersede,
    populationRequirement: PopulationRequirement::Optional,
    description: 'Named entitlement-key bundles for plans. Read a bundle by name; keysForMany() unions the keys across several bundles.',
)]
class BundleRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.accounts.entitlements.bundles';
    }

    /**
     * The deduped entitlement-key set a named bundle declares. An unknown bundle → the empty set (a plan may
     * map to a tier that declares no bundle yet).
     *
     * Guarded on {@see Key::tryParse()} because a bundle name reaches here from a host's plan model as
     * often as from its config: a name outside the key grammar used to answer `[]` and would now throw
     * `InvalidRegistryKey`. Undeclared is undeclared, whatever the spelling.
     *
     * @return list<string>
     */
    public function keysFor(string $bundle): array
    {
        if (Key::tryParse($bundle) === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            'strval',
            (array) ($this->tryResolve($bundle) ?? [])
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
            $keys = [...$keys, ...$this->keysFor($bundle)];
        }

        return array_values(array_unique($keys));
    }

    /** Whether a bundle name is declared. */
    public function has(RegistryKey|string $key): bool
    {
        if (is_string($key) && Key::tryParse($key) === null) {
            return false;
        }

        return parent::has($key);
    }

    /**
     * @return list<string> every declared bundle name, as the host spelled it — `relativeKeys()` rather
     *                      than `keys()`, because keys go relative in and absolute out (ticket 20 D2) and
     *                      a caller-facing list wants the caller's spelling.
     */
    public function names(): array
    {
        return $this->store()->relativeKeys();
    }
}
