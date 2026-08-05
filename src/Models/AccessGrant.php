<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Rushing\PermissionCascade\Contracts\AccessGrant as AccessGrantContract;
use Splicewire\Beam\Beam;

/**
 * The OOTB directory-ACL grant model — a deny-capable sharing row implementing
 * permission-cascade's {@see AccessGrantContract}. The cascade package is model-free (it ships
 * the contract + resolution logic); beam-accounts supplies the default Eloquent model so any
 * beam host gets explicit grants out of the box (the provider binds it as
 * `config('permission-cascade.grant_model')` unless the host overrides).
 *
 * Distinct from a host's *entitlement* ledger (e.g. audiostud's `OwnershipGrant`): this is
 * access control — `grantable` (a HasVisibility object) × `grantee` (a User or Role) ×
 * `ability` ∈ {view, manage} × `effect` ∈ {allow, deny}. Precedence is resolved in the
 * cascade policy, not here.
 */
class AccessGrant extends Model implements AccessGrantContract
{
    protected $guarded = [];

    /** `access_grants` → `beam_access_grants`, via the single table-prefix seam {@see Beam::table()}. */
    public function getTable(): string
    {
        return Beam::table('access_grants');
    }

    public function grantable(): MorphTo
    {
        return $this->morphTo();
    }

    public function grantee(): MorphTo
    {
        return $this->morphTo();
    }
}
