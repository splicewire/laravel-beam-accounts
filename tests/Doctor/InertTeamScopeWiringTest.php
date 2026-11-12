<?php

namespace Splicewire\Beam\Accounts\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Spatie\Permission\PermissionRegistrar;
use Splicewire\Beam\Accounts\Doctor\TeamScopeWiringAudit;
use Splicewire\Beam\Accounts\Tests\TestCase;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Tenancy;
use Stancl\Tenancy\TenancyServiceProvider;

/**
 * fail-closed-guards 02, the amend (review-r1's REVISE on 5110871): a configured, bound PermissionsTenancyBootstrapper is
 * NOT a wired adapter unless Stancl's TenancyInitialized → BootstrapTenancy listener runs it. Without the listener,
 * tenancy initializes but the registrar's team id stays null — the silent total denial — so the audit must FAIL and
 * name the listener. review-r1's repro, made portable (a stand-in bootstrapper instead of beam-tenancy's source file),
 * with its positive control.
 */
class InertTeamScopeWiringTest extends TestCase
{
    private const BOOTSTRAPPER = 'Splicewire\\Beam\\Tenancy\\PermissionsTenancyBootstrapper';

    public function test_a_configured_bootstrapper_without_the_bootstrap_listener_fails_and_names_it(): void
    {
        require_once __DIR__.'/Fixtures/PermissionsTenancyBootstrapper.php';
        config()->set('tenancy.bootstrappers', [self::BOOTSTRAPPER]);
        $this->app->register(TenancyServiceProvider::class);
        $this->assertTrue($this->app->bound(self::BOOTSTRAPPER));
        $this->assertFalse($this->app['events']->hasListeners(TenancyInitialized::class));

        // Tenancy initializes, but nothing runs the bootstrapper: the team scope stays unbound.
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        $tenancy = $this->app->make(Tenancy::class);
        $tenancy->initialize(new Tenant(['id' => 'review-team']));
        $this->assertTrue($tenancy->initialized);
        $this->assertNull($registrar->getPermissionsTeamId());

        $finding = $this->app->make(TeamScopeWiringAudit::class)->run()[0];
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertSame('team-scope-unwired', $finding->check);
        $this->assertStringContainsString('BootstrapTenancy', $finding->detail);

        // Positive control: the exact missing listener activates the same bootstrapper, and the audit then passes.
        $this->app['events']->listen(TenancyInitialized::class, BootstrapTenancy::class);
        $tenancy->end();
        $tenancy->initialize(new Tenant(['id' => 'review-team']));
        $this->assertSame('review-team', $registrar->getPermissionsTeamId());

        $wired = $this->app->make(TeamScopeWiringAudit::class)->run()[0];
        $this->assertSame(DoctorStatus::Pass, $wired->status);
        $this->assertSame('team-scope-wired', $wired->check);
    }
}
