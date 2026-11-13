<?php

namespace Splicewire\Beam\Accounts\Localization;

use Illuminate\Auth\Access\AuthorizationException;

/** Remains catchable as AuthorizationException by existing team callers. */
class AccountAuthorizationException extends AuthorizationException
{
    public function __construct(public readonly AccountError $error, public readonly array $params = [])
    {
        parent::__construct($error->message($params));
    }
}
