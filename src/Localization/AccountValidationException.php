<?php

namespace Splicewire\Beam\Accounts\Localization;

use Splicewire\Beam\Localization\CodedValidationException;

/** A known framework translation key can supply the copy without losing its stable code. */
class AccountValidationException extends CodedValidationException
{
    public static function forMessage(string $field, AccountError $error, string $message): static
    {
        $exception = static::withMessages([$field => $message]);
        $exception->codes = [$field => [$error->code()]];

        return $exception;
    }
}
