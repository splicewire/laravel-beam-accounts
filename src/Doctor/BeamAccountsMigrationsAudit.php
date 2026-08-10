<?php

namespace Splicewire\Beam\Accounts\Doctor;

use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;

class BeamAccountsMigrationsAudit extends StubMigrationsAudit
{
    protected function packageName(): string
    {
        return 'splicewire/laravel-beam-accounts';
    }

    protected function serviceProviderClass(): string
    {
        return BeamAccountsServiceProvider::class;
    }
}
