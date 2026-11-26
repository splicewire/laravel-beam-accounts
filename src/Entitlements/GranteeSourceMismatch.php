<?php

namespace Splicewire\Beam\Accounts\Entitlements;

use RuntimeException;
use Splicewire\Beam\Accounts\Localization\AccountError;
use Throwable;

/** A configured grantee source returned a key the host grant table cannot store. */
final class GranteeSourceMismatch extends RuntimeException
{
    public function __construct(
        public readonly AccountError $error,
        public readonly array $params,
        Throwable $previous,
    ) {
        parent::__construct($error->message($params), 0, $previous);
    }
}
