<?php

namespace Splicewire\Beam\Accounts\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;

/**
 * A host's UUID-KEYED personal-access-token model — the shape every splicewire-operated host
 * (tower, satellite, the flagship) actually runs, and the one the package's own docblock names:
 * "the central-vs-per-tenant connection and a uuid key are HOST concerns a satellite layers on its
 * own subclass".
 *
 * The package's bigint-keyed default is what made the int-cast wire defect invisible for so long:
 * `(int) '7'` is `7`, so every bigint host agreed with itself while every uuid host collapsed its
 * whole roster onto the same id. `tests/Fixtures/AltPersonalAccessToken` models a different TABLE;
 * this models a different KEY TYPE, which is the axis that was wrong.
 */
class UuidPersonalAccessToken extends PersonalAccessToken
{
    use HasUuids;

    /**
     * The SAME table as the package model — only the key type differs. Eloquent would otherwise
     * infer `uuid_personal_access_tokens` from the class name, which would make this fixture a test
     * of a second table rather than of a second key shape (that is `AltPersonalAccessToken`'s job).
     */
    protected $table = 'personal_access_tokens';
}
