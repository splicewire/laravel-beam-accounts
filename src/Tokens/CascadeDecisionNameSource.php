<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Splicewire\Beam\Accounts\Contracts\DecisionNameSource;

/**
 * The permission cascade's decision names as a {@see DecisionNameSource} (credential-scope 04; the adapter 03 asked
 * 02 for): every name registered in the cascade's `DecisionNames` (and `permission-cascade.decision_names`) and, for
 * every policy the Gate knows, each verb's class-level names ({@see CredentialScopeGate::offerableNames()}).
 *
 * Every name is offered to every user: the principal half of each decision bounds what a token carrying it can do
 * (ADR 0006: "the principal may AND the token carries the decision's name"), so offering a name a user cannot reach
 * grants nothing. Per-record `{model}.{id}.{verb}` names are never offered; they are unbounded.
 *
 * Built in to {@see WidestTokenScope}, so a host whose published `tokens` config predates
 * `decision_name_sources` still gets it.
 */
class CascadeDecisionNameSource implements DecisionNameSource
{
    public function namesFor(Authenticatable $user): iterable
    {
        return app(CredentialScopeGate::class)->offerableNames();
    }
}
