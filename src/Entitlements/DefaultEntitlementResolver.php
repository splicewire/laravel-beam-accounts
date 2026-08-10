<?php

namespace Splicewire\Beam\Accounts\Entitlements;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;

/**
 * The OOTB host-principal entitlement resolver — the working default a fresh beam host gets so its
 * staff-gated surfaces (the operator back-office `/operator`, the OS-shell `/os`, the authoring chrome)
 * actually resolve instead of 403-ing everyone.
 *
 * ## What it does
 *
 * A STAFF principal resolves the configured **staff bundle** (`config('beam.accounts.entitlements.default_staff_bundle')`,
 * default `staff` → `['author-ux', 'os.enter', 'app-operator']`); every other principal resolves the empty set.
 * The set is folded through the {@see EntitlementComposer} over the named bundles in
 * `config('beam.accounts.entitlements.bundles')`, so a host that wants to add plan/grant/deny logic subclasses or
 * replaces this without re-deriving the algebra. This is the domain-neutral floor; audiostud's
 * `AudiostudEntitlementResolver` is the richer, plan-aware form of the same shape.
 *
 * ## Staff-detection convention (resilient, documented)
 *
 * A principal is "staff" if EITHER:
 *
 *  1. it exposes a truthy **`is_staff`** attribute (the flat boolean column convention — the cheapest, host-owned
 *     signal; a host adds an `is_staff` migration + a factory state), OR
 *  2. it holds a **`staff`** or **`operator`** spatie role (the permission-cascade role convention the beam
 *     `User` already carries via `HasRoles`) — checked via `hasRole()` when the principal exposes it.
 *
 * Either signal alone entitles; a host picks whichever fits its schema. `is_staff` is checked first (a plain
 * attribute read is cheaper and never touches the roles tables). A guest / unknown principal (no attribute, no
 * role method) is inert — the null-default discipline (ADR-0009).
 *
 * ## Binding
 *
 * The {@see \Splicewire\Beam\Accounts\BeamAccountsServiceProvider} binds this CONDITIONALLY — only when the host
 * has NOT set `config('permission-cascade.entitlement_resolver')` — so a host override (audiostud's resolver)
 * always wins. Binding it is what turns beam's `can:entitlement:{key}` gates on: permission-cascade always binds
 * the `EntitlementResolver` contract (to the null resolver when unconfigured), so beam's
 * `registerEntitlementAbilities()` already defines the gates; this default just makes them return `true` for a
 * staff principal instead of the empty set. No `laravel-beam` edit is required.
 */
class DefaultEntitlementResolver implements EntitlementResolver
{
    public function __construct(private EntitlementComposer $composer) {}

    /**
     * The entitlement keys `$principal` holds. Staff → the staff bundle; everyone else → the empty set.
     *
     * @return array<int, string>
     */
    public function entitlementsFor(mixed $principal): array
    {
        if (! $this->isStaff($principal)) {
            return [];
        }

        $bundle = (string) config('beam.accounts.entitlements.default_staff_bundle', 'staff');

        return $this->composer->composeForBundles($bundle);
    }

    /**
     * Resilient staff detection: a truthy `is_staff` attribute OR a `staff`/`operator` spatie role.
     */
    protected function isStaff(mixed $principal): bool
    {
        if (! is_object($principal)) {
            return false;
        }

        // (1) The flat boolean column convention — cheapest signal, never touches the roles tables.
        $isStaff = $this->readAttribute($principal, 'is_staff');
        if ($isStaff) {
            return true;
        }

        // (2) The spatie role convention (the beam User carries HasRoles). Guard on the method so a
        // principal without roles support (or a bare stub) degrades to "not staff" rather than erroring.
        if (method_exists($principal, 'hasRole')) {
            foreach ((array) config('beam.accounts.entitlements.staff_roles', ['staff', 'operator']) as $role) {
                if ($principal->hasRole($role)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Read a principal attribute resiliently — Eloquent `getAttribute()` first, then a public property,
     * so a plain stub (no Eloquent) still resolves.
     */
    protected function readAttribute(object $principal, string $key): mixed
    {
        if (method_exists($principal, 'getAttribute')) {
            return $principal->getAttribute($key);
        }

        return $principal->{$key} ?? null;
    }
}
