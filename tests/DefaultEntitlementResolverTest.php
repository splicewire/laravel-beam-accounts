<?php

namespace Splicewire\Beam\Accounts\Tests;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Spatie\Permission\Models\Role;
use Splicewire\Beam\Accounts\Entitlements\DefaultEntitlementResolver;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The OOTB DefaultEntitlementResolver: a staff principal resolves the staff bundle (author-ux/os.enter/
 * app-operator), a non-staff principal resolves nothing, and a host-declared resolver wins over the default.
 */
class DefaultEntitlementResolverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Rely on the package's shipped default bundles (staff => author-ux/os.enter/app-operator).
    }

    public function test_a_staff_principal_by_attribute_resolves_the_three_staff_keys(): void
    {
        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test']);
        $staff->setAttribute('is_staff', true);

        $this->assertEqualsCanonicalizing(
            ['author-ux', 'os.enter', 'app-operator'],
            $resolver->entitlementsFor($staff)
        );
    }

    public function test_a_staff_principal_by_role_resolves_the_three_staff_keys(): void
    {
        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        Role::create(['name' => 'staff', 'guard_name' => 'web']);
        $user = User::create(['name' => 'Op', 'email' => 'op@example.test']);
        $user->assignRole('staff');

        $this->assertEqualsCanonicalizing(
            ['author-ux', 'os.enter', 'app-operator'],
            $resolver->entitlementsFor($user->fresh())
        );
    }

    public function test_a_non_staff_principal_resolves_no_keys(): void
    {
        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $user = User::create(['name' => 'Nobody', 'email' => 'nobody@example.test']);

        $this->assertSame([], $resolver->entitlementsFor($user));
    }

    public function test_a_guest_or_non_object_principal_resolves_no_keys(): void
    {
        $resolver = $this->app->make(DefaultEntitlementResolver::class);

        $this->assertSame([], $resolver->entitlementsFor(null));
        $this->assertSame([], $resolver->entitlementsFor('a-string'));
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
