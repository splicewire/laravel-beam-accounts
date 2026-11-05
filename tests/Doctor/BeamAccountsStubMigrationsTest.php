<?php

namespace Splicewire\Beam\Accounts\Tests\Doctor;

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Tests\TestCase;
use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;

/**
 * beam-accounts' own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which package is mine."
 */
class BeamAccountsStubMigrationsTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_accounts_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsPackage(): ?string
    {
        return 'splicewire/laravel-beam-accounts';
    }

    protected function stubMigrationsProvider(): ?string
    {
        return BeamAccountsServiceProvider::class;
    }
}
