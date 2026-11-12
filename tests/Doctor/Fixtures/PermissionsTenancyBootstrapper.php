<?php

namespace Splicewire\Beam\Tenancy;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

// A test stand-in for beam-tenancy's PermissionsTenancyBootstrapper, with the same name and the same body: this package
// never requires beam-tenancy, but the inert-wiring regression needs a bootstrapper that really binds the team scope.
// Declared only when the real class is not loaded.
if (! class_exists(PermissionsTenancyBootstrapper::class, false)) {
    class PermissionsTenancyBootstrapper implements TenancyBootstrapper
    {
        public function bootstrap(Tenant $tenant): void
        {
            setPermissionsTeamId($tenant->getTenantKey());
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        public function revert(): void
        {
            setPermissionsTeamId(null);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
