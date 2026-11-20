<?php

namespace Splicewire\Beam\Accounts\Tokens;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A delegated kind bound to the OPERATION in hand rather than to the actor's ambient context: the federation grant a
 * resolve token is minted against, the brokered child a satellite token is for, the tenant an operator command makes
 * a broker (credential-scope 04, answering codex cs03's open question "what independent context resolves a delegated
 * tenant when the actor's context cannot").
 *
 * The operation is handed to {@see MachineTokenIssuer::issueFor()} by the server-side code that performs it, never
 * read from a request body, and it is the kind's ONLY subject: {@see boundSubject()} is final and answers it, so
 * {@see MachineTokenIssuer::issue()} on such a kind resolves no subject and is refused, and a request's `subject` is
 * still only a claim checked against it. The operation narrows nothing on its own: every rule of a delegated kind
 * still applies, and {@see authorizes()} must establish the actor's live authority over the target FOR this
 * operation (a live grant, a broker's grant over a child's parent, an operator), or the issuance is refused.
 */
abstract class OperationTokenKind extends DelegatedTokenKind
{
    private mixed $operation = null;

    /** A copy of this kind bound to `$operation`. The issuer calls this; nothing else should. */
    final public function forOperation(mixed $operation): static
    {
        $bound = clone $this;
        $bound->operation = $operation;

        return $bound;
    }

    final public function boundSubject(?Authenticatable $actor): mixed
    {
        return $this->operation;
    }
}
