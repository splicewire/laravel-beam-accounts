<?php

namespace Splicewire\Beam\Accounts\Localization;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Preserve Laravel abort(404)'s catchable parent while retaining the account identity. */
class AccountNotFoundHttpException extends NotFoundHttpException
{
    public function __construct(public readonly AccountError $error, public readonly array $params = [])
    {
        parent::__construct($error->message($params));
    }
}
