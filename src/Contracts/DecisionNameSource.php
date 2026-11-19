<?php

namespace Splicewire\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Names of authorization decisions a principal can reach that are not in its permission snapshot (ADR 0006,
 * "Everything I hold changes meaning").
 *
 * Under a scoped credential every decision is "the principal may AND the token carries the decision's name", and
 * a decision that asks no permission (membership, ownership, participant, seller) has a REGISTERED name rather than
 * a permission row. A token built from `getAllPermissions()` alone is refused at those decisions. The widest set a
 * user can scope a token to is therefore the snapshot plus these names; {@see \Splicewire\Beam\Accounts\Tokens\WidestTokenScope}
 * unions every source listed in `beam.accounts.tokens.decision_name_sources`.
 *
 * The permission cascade's decision-name registry (credential-scope 02) is the first source. Offering a name here
 * never grants it: the principal half of every decision still applies.
 */
interface DecisionNameSource
{
    /** @return iterable<string> */
    public function namesFor(Authenticatable $user): iterable;
}
