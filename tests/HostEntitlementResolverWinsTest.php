<?php

namespace Splicewire\Beam\Accounts\Tests;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;

/**
 * Host-binding-wins: when the host has already declared `permission-cascade.entitlement_resolver`, the
 * package's conditional default MUST NOT overwrite it. `defineEnvironment` sets the config before the
 * package provider's `register()` runs, so this reproduces a host that binds its own resolver.
 */
class HostEntitlementResolverWinsTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('permission-cascade.entitlement_resolver', HostResolverStub::class);
    }

    public function test_a_host_declared_resolver_is_not_overwritten_by_the_default(): void
    {
        $this->assertSame(
            HostResolverStub::class,
            config('permission-cascade.entitlement_resolver')
        );

        $this->assertInstanceOf(
            HostResolverStub::class,
            $this->app->make(EntitlementResolver::class)
        );
    }
}

class HostResolverStub implements EntitlementResolver
{
    public function entitlementsFor(mixed $principal): array
    {
        return ['host-key'];
    }
}
