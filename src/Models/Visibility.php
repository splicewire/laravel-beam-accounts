<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Rushing\PermissionCascade\Contracts\VisibilityRecord as VisibilityRecordContract;
use Splicewire\Beam\Facades\Beam;

/**
 * The OOTB off-table reach-tier record — a `reachable` morph row implementing
 * permission-cascade's {@see VisibilityRecordContract}. The cascade package is model-free (it
 * ships the contract + resolution logic); beam-accounts supplies the default Eloquent model so
 * any beam host gets tier storage without adding a `visibility` column to every policied
 * model's own table (the provider binds it as `config('permission-cascade.visibility_model')`
 * unless the host overrides). Mirrors {@see AccessGrant}'s model-free shape exactly.
 *
 * One row per policied record (a DB-enforced unique `reachable` pair, unlike AccessGrant's
 * many-per-record morphMany) — a model with no row here has no explicit tier, the off-table
 * equivalent of a NULL `visibility` column.
 */
class Visibility extends Model implements VisibilityRecordContract
{
    protected $guarded = [];

    protected $casts = [
        'listed' => 'boolean',
    ];

    /** `visibilities` → `beam_visibilities`, via the single table-prefix seam {@see Beam::table()}. */
    public function getTable(): string
    {
        return Beam::table('visibilities');
    }

    public function reachable(): MorphTo
    {
        return $this->morphTo();
    }
}
