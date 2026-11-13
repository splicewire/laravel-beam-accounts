<?php

namespace Splicewire\Beam\Accounts\Localization;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A normal HTTP refusal, with its stable account identity retained for JSON rendering. */
class AccountHttpException extends HttpException
{
    public function __construct(int $status, public readonly AccountError $error, public readonly array $params = [])
    {
        parent::__construct($status, $error->message($params));
    }
}
