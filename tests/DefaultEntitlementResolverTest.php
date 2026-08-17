<?php

namespace Splicewire\Beam\Accounts\Tests;

use Rushing\PermissionCascade\Contracts\AccessGrant;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Sharing\AccessGrants;
use Splicewire\Beam\Accounts\Tests\Fixtures\RealmRoot;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use stdClass;

/**
 * The OOTB DefaultEntitlementResolver post-ACC-01: `is_staff` is gone entirely. A principal resolves
 * `ux.{realm}.author` (+ the coarse `ux.author` alias, + `os.enter`/`os.operate` for the `operator`
 * realm) off `manage` grants an Owner/Admin-held Team holds on a realm's root — data, not a flag. A
 * host-declared resolver still wins over the default.
 */
class DefaultEntitlementResolverTest extends TestCase
{
    protected function team(): Team
    {
        $owner = User::create(['name' => 'Team Owner', 'email' => uniqid('team-owner-').'@example.test']);

        return Team::create(['user_id' => $owner->id, 'name' => 'A Team', 'personal_team' => false]);
    }

    protected function memberOf(Team $team, Role $role): User
    {
        $user = User::create(['name' => $role->value, 'email' => "{$role->value}@example.test"]);
        Membership::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => $role->value]);

        return $user->fresh();
    }

    public function test_an_owner_of_a_team_holding_a_realm_root_grant_resolves_the_realm_and_alias_keys(): void
    {
        $team = $this->team();
        $owner = $this->memberOf($team, Role::Owner);
        $root = RealmRoot::create(['realm' => 'site']);
        app(AccessGrants::class)->share($root, $team, AccessGrant::ABILITY_MANAGE);

        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertEqualsCanonicalizing(
            ['ux.site.author', 'ux.author'],
            $resolver->entitlementsFor($owner)
        );
    }

    public function test_an_admin_of_a_team_holding_the_operator_realm_grant_also_resolves_os_enter_and_os_operate(): void
    {
        $team = $this->team();
        $admin = $this->memberOf($team, Role::Admin);
        $root = RealmRoot::create(['realm' => 'operator']);
        app(AccessGrants::class)->share($root, $team, AccessGrant::ABILITY_MANAGE);

        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertEqualsCanonicalizing(
            ['ux.operator.author', 'ux.author', 'os.enter', 'os.operate'],
            $resolver->entitlementsFor($admin)
        );
    }

    public function test_a_team_without_a_grant_resolves_no_keys(): void
    {
        $team = $this->team();
        $owner = $this->memberOf($team, Role::Owner);
        RealmRoot::create(['realm' => 'site']); // no grant minted

        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertSame([], $resolver->entitlementsFor($owner));
    }

    public function test_a_member_role_cannot_exercise_a_grant_even_when_their_team_has_one(): void
    {
        $team = $this->team();
        $member = $this->memberOf($team, Role::Member);
        $root = RealmRoot::create(['realm' => 'site']);
        app(AccessGrants::class)->share($root, $team, AccessGrant::ABILITY_MANAGE);

        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertSame([], $resolver->entitlementsFor($member));
    }

    public function test_a_stale_or_unrecognized_membership_role_degrades_to_ineligible_rather_than_throwing(): void
    {
        $team = $this->team();
        $user = User::create(['name' => 'Stale', 'email' => 'stale@example.test']);
        Membership::create(['team_id' => $team->id, 'user_id' => $user->id, 'role' => 'legacy-superuser']);
        $root = RealmRoot::create(['realm' => 'site']);
        app(AccessGrants::class)->share($root, $team, AccessGrant::ABILITY_MANAGE);

        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertSame([], $resolver->entitlementsFor($user->fresh()));
    }

    public function test_a_guest_or_non_object_principal_resolves_no_keys(): void
    {
        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertSame([], $resolver->entitlementsFor(null));
        $this->assertSame([], $resolver->entitlementsFor('a-string'));
    }

    public function test_a_principal_without_a_teams_relation_resolves_no_keys(): void
    {
        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertSame([], $resolver->entitlementsFor(new stdClass));
    }

    public function test_the_default_is_bound_via_config_when_the_host_declares_none(): void
    {
        // The package sets permission-cascade.entitlement_resolver to the default in register()
        // (host had not set it), so the kernel's EntitlementResolver singleton resolves to it.
        $this->assertSame(
            DefaultEntitlementResolver::class,
            config('permission-cascade.entitlement_resolver')
        );

        $this->assertInstanceOf(
            DefaultEntitlementResolver::class,
            $this->app->make(EntitlementResolver::class)
        );
    }
}
