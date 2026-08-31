<?php

namespace Splicewire\Beam\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Splicewire\Beam\Accounts\Contracts\TeamContract;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Facades\Beam;

class Invitation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'accepted_at' => 'datetime',
        // `team_id` holds a {@see TeamContract} key, and that contract declares it `int|string`. The
        // column is therefore a plain string (see `create_invitations_table.php.stub`), and the cast
        // is what stops the widening from buying a silent-coercion bug in exchange: without it a
        // bigint-keyed host reads back `5` and a string-keyed host reads back `"5"`, so `===` against
        // `teamKey()` would be true at one host and false at another for the same team.
        'team_id' => 'string',
    ];

    /**
     * The single-use accept `token` is a bearer secret — anyone holding it can join the team. Hidden
     * from every array/JSON serialization so it cannot ride the wire off an unguarded projection.
     * The accept flow queries by token in a WHERE clause and never serializes it.
     */
    protected $hidden = [
        'token',
    ];

    /**
     * `invitations` → `beam_invitations`, via the single table-prefix seam {@see Beam::table()}
     * (beam-particle-rename ticket 04).
     */
    public function getTable(): string
    {
        return Beam::table('invitations');
    }

    /**
     * The owning team AS BEAM'S OWN `Team`.
     *
     * ⚠️ Correct only where the host's team notion IS {@see Team}. `team_id` is a `TeamContract` key
     * and the contract has more than one implementation, so a host that binds
     * `beam.accounts.teams.resolver` to something else (beam-tenancy's `Tenant`) must read its team
     * through that resolver, not through this relation — which would look for a `beam_teams` row
     * that does not exist and answer null. Kept because it is the reference implementation's
     * relation and because nothing else in the package reads it; deliberately NOT widened into a
     * morph, since a host has exactly one team notion and a discriminator that never discriminates
     * buys only a forgery axis (see the stub's note).
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The user who sent the invitation, resolved through the configured Authenticatable rather than
     * an imported host class.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(BeamAccounts::userModel(), 'invited_by');
    }
}
