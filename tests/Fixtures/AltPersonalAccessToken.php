<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Splicewire\Beam\Accounts\Models\PersonalAccessToken;

/**
 * A host's BESPOKE personal-access-token model, modelled as the one thing that actually
 * distinguishes it: a different table.
 *
 * It exists for a single assertion — that the account-tier token mint follows
 * `beam.accounts.tokens.model` rather than Sanctum's `Sanctum::personalAccessTokenModel()` global.
 * The two are only distinguishable when they disagree, and a host with a bespoke PAT (its own
 * connection, a uuid key) is exactly the case where they do: `$user->createToken()` would write
 * Sanctum's model while every read on this surface queries the configured one, so the token would
 * land in a table the list never opens.
 */
class AltPersonalAccessToken extends PersonalAccessToken
{
    protected $table = 'alt_personal_access_tokens';
}
