<?php

namespace Splicewire\Beam\Accounts\Entitlements;

use Rushing\PermissionCascade\Contracts\AccessGrant;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\Contracts\RealmGrantable;
use Splicewire\Beam\Accounts\Enums\Role;

/**
 * The OOTB host-principal entitlement resolver — the working default a fresh beam host gets so its
 * staff-gated surfaces (the operator back-office `/operator`, the OS-shell `/os`, the authoring chrome)
 * actually resolve instead of 403-ing everyone.
 *
 * ## What it does (ACC-01 — the `is_staff` retirement)
 *
 * `is_staff` is gone: there is no flag anywhere in this resolution. A principal's authoring/operator
 * reach is DATA — `manage` grants (`rushing/laravel-permission-cascade`'s `AccessGrant`/`HasVisibility`
 * directory ACL) held by a Team the principal belongs to as Owner or Admin ({@see Role::grantEligible()}
 * — Member is excluded regardless of what the team holds), on a realm's root grantable row. For each
 * granted realm the principal composes `author-ux-{realm}`; if ANY realm is granted, the coarse
 * `author-ux` alias also composes; if the `operator` realm specifically is granted, `os.enter` and
 * `app-operator` compose too — reproducing today's blanket staff capability exactly, but as a Team's
 * grants rather than a boolean column. No special-cased "Staff Team" identity is needed anywhere in
 * this class: any Team holding `manage` on every realm's root behaves identically to the old
 * `is_staff = true` principal.
 *
 * The realm-root lookup itself is a port ({@see RealmGrantable}) — this class never names
 * `BeamUxEntry` (the paid beam-ux model realm roots actually are), since the dependency runs
 * beam-ux → beam-accounts, never the reverse. Unbound (`config('beam.accounts.entitlements.realm_grantable')`
 * null), the grant cascade resolves no realms — the null-default discipline (ADR-0009).
 *
 * The derived key set is folded through the {@see EntitlementComposer}, so a host that wants to layer
 * plan/grant/deny logic on top subclasses or replaces this without re-deriving the algebra.
 *
 * ## Binding
 *
 * The `Splicewire\Beam\Accounts\BeamAccountsServiceProvider` binds this CONDITIONALLY — only when
 * the host has NOT set `config('permission-cascade.entitlement_resolver')` — so a host override (audiostud's
 * resolver) always wins. Binding it is what turns beam's `can:entitlement:{key}` gates on: permission-cascade
 * always binds the `EntitlementResolver` contract (to the null resolver when unconfigured), so beam's
 * `registerEntitlementAbilities()` already defines the gates; this default just makes them return `true` for
 * a principal whose eligible team holds the matching grant. No `laravel-beam` edit is required.
 */
class DefaultEntitlementResolver implements EntitlementResolver
{
    /** The realm whose grant additionally unlocks the OS-shell + operator back-office. */
    public const OPERATOR_REALM = 'operator';

    public function __construct(private EntitlementComposer $composer) {}

    /**
     * The entitlement keys `$principal` holds, derived entirely from its eligible teams' realm grants.
     *
     * @return array<int, string>
     */
    public function entitlementsFor(mixed $principal): array
    {
        $realms = $this->grantedRealms($principal);

        $keys = array_map(static fn (string $realm): string => "author-ux-{$realm}", $realms);

        if ($realms !== []) {
            $keys[] = 'author-ux';
        }

        if (in_array(self::OPERATOR_REALM, $realms, true)) {
            $keys[] = 'os.enter';
            $keys[] = 'app-operator';
        }

        return $this->composer->compose([], $keys);
    }

    /**
     * The realms `$principal`'s eligible (Owner/Admin) team memberships hold a `manage` grant on the
     * realm's root entry for, deduped. Empty when the principal exposes no `teams()` relation, holds no
     * eligible membership, or the host hasn't bound a {@see RealmGrantable}.
     *
     * @return list<string>
     */
    protected function grantedRealms(mixed $principal): array
    {
        if (! is_object($principal) || ! method_exists($principal, 'teams')) {
            return [];
        }

        $grantable = $this->realmGrantable();
        $grantModel = config('permission-cascade.grant_model');

        if ($grantable === null || $grantModel === null) {
            return [];
        }

        $realms = [];

        foreach ($principal->teams as $team) {
            $role = $team->pivot->role ?? null;
            $role = $role !== null ? Role::tryFrom($role) : null;

            // A null (absent) or unrecognized role degrades to "not eligible" rather than throwing —
            // a stale/hand-edited membership row must never fatal every entitlement Gate check.
            if ($role === null || ! $role->grantEligible()) {
                continue;
            }

            $ids = $grantModel::query()
                ->where('grantable_type', $grantable->grantableMorphType())
                ->where('grantee_type', $team->getMorphClass())
                ->where('grantee_id', (string) $team->getKey())
                ->where('ability', AccessGrant::ABILITY_MANAGE)
                ->where('effect', AccessGrant::EFFECT_ALLOW)
                ->pluck('grantable_id');

            foreach ($ids as $id) {
                $realm = $grantable->realmForGrantableId((string) $id);
                if ($realm !== null) {
                    $realms[$realm] = true;
                }
            }
        }

        return array_keys($realms);
    }

    /** The host-bound realm-root port, or null when unconfigured. */
    protected function realmGrantable(): ?RealmGrantable
    {
        $class = config('beam.accounts.entitlements.realm_grantable');

        return $class !== null ? app($class) : null;
    }
}
