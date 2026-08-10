<?php

namespace Splicewire\Beam\Accounts\Tests\Doctor;

use Splicewire\Beam\Accounts\Doctor\BeamAccountsMigrationsAudit;
use Splicewire\Beam\Accounts\Tests\TestCase;
use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;

/**
 * beam-accounts' own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which audit is mine."
 */
class BeamAccountsMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_accounts_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamAccountsMigrationsAudit::class;
    }
}
