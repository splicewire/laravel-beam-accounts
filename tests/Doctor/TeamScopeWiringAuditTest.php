<?php

namespace Splicewire\Beam\Accounts\Tests\Doctor;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Doctor\TeamScopeWiringAudit;
use Splicewire\Beam\Accounts\Tests\TestCase;

/**
 * fail-closed-guards ticket 02: zero wired adapters is a FAIL (silent total denial), one is a pass
 * naming the adapter, two is a warn. The harness wires nothing by default, which is exactly the
 * state the audit must refuse to call healthy.
 */
class TeamScopeWiringAuditTest extends TestCase
{
    private function findings(): array
    {
        return $this->app->make(TeamScopeWiringAudit::class)->run();
    }

    public function test_zero_adapters_fails_loudly(): void
    {
        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame('team-scope-unwired', $findings[0]->check);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('AUTHORIZES NOTHING', $findings[0]->detail);
    }

    public function test_the_web_middleware_adapter_passes(): void
    {
        Route::middlewareGroup('web', ['splicewire.team']);

        $findings = $this->findings();

        $this->assertSame('team-scope-wired', $findings[0]->check);
        $this->assertStringContainsString('splicewire.team', $findings[0]->detail);
    }

    public function test_the_tenancy_bootstrapper_adapter_passes(): void
    {
        config()->set('tenancy.bootstrappers', [
            'Splicewire\\Beam\\Tenancy\\PermissionsTenancyBootstrapper',
        ]);

        $findings = $this->findings();

        $this->assertSame('team-scope-wired', $findings[0]->check);
        $this->assertStringContainsString('PermissionsTenancyBootstrapper', $findings[0]->detail);
    }

    public function test_both_adapters_warn(): void
    {
        Route::middlewareGroup('web', ['splicewire.team']);
        config()->set('tenancy.bootstrappers', [
            'Splicewire\\Beam\\Tenancy\\PermissionsTenancyBootstrapper',
        ]);

        $findings = $this->findings();

        $this->assertSame('team-scope-double-wired', $findings[0]->check);
    }
}
