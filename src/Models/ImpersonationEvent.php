<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Facades\Beam;

/**
 * One append-only audit row per operator impersonation start/stop — actor (staff), subject
 * (customer), action, timestamp.
 *
 * Lifted from the byte-identical `App\Models\ImpersonationEvent` that audiostud and numero each
 * carried (particle-identity-resources ticket 03). The two differed only by the ADR number in a
 * docblock.
 *
 * KEYS ARE STRINGS, and that is the one place the package had to diverge from both originals:
 * audiostud declared `actor_id` as `foreignUuid`, numero as `foreignId`. A package table cannot be
 * both, so it takes the same route `beam_view_requests` already takes for its morph keys — plain
 * strings, no cross-type foreign keys — and the host's own key type is preserved on the way in and
 * out. A host that would rather keep its EXISTING typed table with its foreign keys points
 * `beam.accounts.impersonation.table` at it and skips the published migration entirely; that is the
 * no-data-migration path, and it is the recommended one for the two hosts that already have rows.
 *
 * Append-only: `UPDATED_AT` is null and nothing in this package updates a row. The audit value comes
 * from the trail being immutable, so a host that adds an edit path is defeating the point.
 */
class ImpersonationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'subject_id', 'action'];

    /**
     * `beam_impersonation_events` by default, through the single table-prefix seam
     * {@see Beam::table()} — or whatever `beam.accounts.impersonation.table` names, which is how a
     * host keeps the `impersonation_events` table it already has.
     */
    public function getTable(): string
    {
        return config('beam.accounts.impersonation.table')
            ?: Beam::table('impersonation_events');
    }
}
