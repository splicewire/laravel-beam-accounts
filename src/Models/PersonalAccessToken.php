<?php

namespace Splicewire\Beam\Accounts\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;

/**
 * The OOTB personal-access-token model for the account Tokens resource (Frame OS ticket 20).
 *
 * beam-accounts already owns the token SCHEMA — the `provenance` + `archived_at` columns
 * (`add_provenance_to_personal_access_tokens_table` / `add_archived_at_to_...`) — so it owns
 * the model too. It extends Sanctum's PAT with the extra fillable/cast for those columns and
 * nothing tenant-specific: the central-vs-per-tenant connection and a uuid key are HOST
 * concerns a satellite layers on its own subclass (see {@see BeamAccounts::tokenModel()} — a host
 * binds `beam.accounts.tokens.model` to it). Standalone, this plain model on the default
 * connection is the correct null-object.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * `provenance` and `last_four` are stamped by the minting site (never by a tokenable's mass
     * assignment), so they join the fillable set alongside Sanctum's own token attributes.
     * `last_four` is written through {@see \Splicewire\Beam\Accounts\Keys\TokenLastFour}, which
     * leaves it out on a host whose table has no such column yet.
     */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'provenance',
        'last_four',
    ];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'archived_at' => 'datetime',
        'provenance' => TokenProvenance::class,
    ];
}
