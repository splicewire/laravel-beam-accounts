<?php

namespace Splicewire\Beam\Accounts\Concerns;

use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\Popcorn\Concerns\Chained;
use Splicewire\Beam\Accounts\Authorization\MembershipPolicy;
use Splicewire\Beam\Accounts\Authorization\UserPolicy;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Teams\TeamMembers;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * One concern of {@see BeamAccountsServiceProvider}, contributed to its `boot` chain by the trait that
 * owns it rather than by a line in the provider's hand-written call block.
 *
 * ⚠️ `bootAuthoringGates()` travels with it and carries NO attribute: it is called BY
 * `bootAuthorization()`, not by the chain. A second link would run it twice.
 *
 * Order is DECLARED, never positional: `pint`'s Laravel preset sorts a class's `use` statements
 * alphabetically, so a chain resting on `use` position would be resequenced by a formatter.
 */
trait WiresAuthorization
{
    /**
     * The team-membership authorization seam. Registers the membership abilities so
     * every consumer — the engine's own {@see TeamMembers} lifecycle and any host
     * controller — authorizes through one named check instead of hand-rolling role
     * comparisons. Two graduated tiers on the one membership axis: `manageMembers`
     * (change role / remove / ownership transfer) is owner-only; `manageInvitations`
     * (send / resend / revoke) admits owners and admins. See {@see MembershipPolicy}.
     */
    #[Chained('boot', order: 10)]
    protected function bootAuthorization(): void
    {
        Gate::define('manageMembers', [MembershipPolicy::class, 'manageMembers']);
        Gate::define('manageInvitations', [MembershipPolicy::class, 'manageInvitations']);

        // The `users` resource's write gate ({@see UserPolicy}) — needed now that the resource
        // widened `editable`. Registered against the CONFIGURED user model, since hosts routinely
        // subclass ours, and deferred to `booted()` so a host's own AuthServiceProvider has already
        // run: if the host has bound a User policy of its own, that one wins and this is skipped
        // entirely. A package must not silently replace a host's identity policy.
        $this->app->booted(function (): void {
            $model = BeamAccounts::userModel();

            if (Gate::getPolicyFor($model) === null) {
                Gate::policy($model, UserPolicy::class);
            }
        });

        // A share link is managed (revoked) by its minter (ADR-0009, tracer 05). Not
        // team-scoped like invitations — the check is minter-ownership, compared as strings
        // since created_by is a cross-host string key.
        Gate::define('manageShareLinks', function ($user, ShareLink $link) {
            return $link->created_by !== null
                && (string) $link->created_by === (string) $user->getAuthIdentifier();
        });

        $this->bootAuthoringGates();
    }

    /**
     * The bare `ux.author` / `ux.{realm}.author` Gate aliases — normalized here instead of every host
     * hand-rolling the identical pair (found byte-for-byte duplicated, docblock and all, in both
     * `audiostud`'s and `laravel-beam-starter`'s own `AppServiceProvider`, back when these were named
     * `author-ux`/`author-ux-{realm}` — renamed to the dot-cascade fleet-wide). `Gate::define()` is
     * last-write-wins by name, so a host that still defines its own version of either (e.g. to layer
     * extra logic on top) overrides this cleanly — nothing here needs a guard.
     *
     * `ux.author` reads through the `entitlement:ux.author` Gate beam-core's registerEntitlementAbilities()
     * defines (now that {@see self::registerEntitlementKeys()} lists it). `ux.{realm}.author` has no
     * `entitlement:` Gate to ride — it's realm-PARAMETERIZED, and beam-core only defines abilities for
     * the flat key list — so it reads the resolver's raw key list directly instead, over beam-core's
     * RealmRegistry (operator/tenant/site/user by default, plus any host `#[Realm]` preset).
     *
     * Deliberately does NOT fall back to `ux.author` for the per-realm check: `DefaultEntitlementResolver`
     * composes the coarse `ux.author` key as soon as ANY single realm is granted, so a
     * `ux.{realm}.author = ux.author || ...` shortcut would let a grant on just ONE realm leak authoring
     * into every OTHER realm — defeating the whole point of the per-realm grain. A grantee of every realm
     * still authors every realm (each `ux.{realm}.author` key composes independently); a narrowly-granted
     * principal now correctly stays narrow.
     */
    protected function bootAuthoringGates(): void
    {
        Gate::define('ux.author', fn ($user) => $user->can('entitlement:ux.author'));

        foreach (array_keys($this->app->make(RealmRegistry::class)->all()) as $realm) {
            $ability = "ux.{$realm}.author";

            Gate::define($ability, fn ($user) => in_array(
                $ability,
                $this->app->make(EntitlementResolver::class)->entitlementsFor($user),
                true,
            ));
        }
    }
}
