<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;

/**
 * The account rows: the signed-in `email` and an optional `paymentMethodLabel` — a DISPLAY STRING
 * only (e.g. "Visa •••• 4242"). This package holds no card data and makes no gateway call; the host
 * derives the label from whatever billing system it owns (Cashier, a future billing package, nothing).
 */
class AccountData extends Data
{
    public function __construct(
        public string $email,
        public ?string $paymentMethodLabel = null,
    ) {}
}
