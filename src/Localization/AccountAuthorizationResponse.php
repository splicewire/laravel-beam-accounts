<?php

namespace Splicewire\Beam\Accounts\Localization;

use Illuminate\Auth\Access\Response;

/** Identifies an accounts policy refusal without treating another package's code as ours. */
class AccountAuthorizationResponse extends Response
{
    public function __construct(public readonly AccountError $error, array $params = [])
    {
        parent::__construct(false, $error->message($params), $error->code());
    }
}
