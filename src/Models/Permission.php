<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * `spatie/laravel-permission`'s Permission, keyed by uuid to match beam-accounts'
 * `create_permission_tables` stub (beam-facade ticket 98).
 *
 * See {@see Role}'s docblock for the whole argument: why the uuid key is a constraint this package
 * imposes on itself, why `HasUuids` is the repair, why **nothing binds this pair**, and how a host
 * reaches it. Permission has no `findOrCreate()` call site in this package today — it ships as the
 * pair's other half because the migration keys both tables the same way, and a host that binds one
 * without the other gets a `model_has_permissions` join that fails exactly as the roles one would.
 */
class Permission extends SpatiePermission
{
    use HasUuids;
}
