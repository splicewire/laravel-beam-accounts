<?php

namespace Splicewire\Beam\Accounts\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;

/**
 * The account-area SHAPE the shell renders — plan chip, public profile, account rows, upsell CTAs.
 * A pure DATA-SHAPE contract, NOT a billing engine: this package projects the shape and provides a
 * bindable provider seam ({@see AccountShellProvider}); the HOST
 * populates every value from its own product data. It owns no Cashier/charge logic, no gateway call,
 * and no monetization copy — the payment field is a DISPLAY LABEL only (e.g. "Visa •••• 4242"), never
 * card data. A host that binds no provider gets `null` (the null default), so the shell degrades
 * gracefully instead of 500-ing.
 */
class AccountShellData extends Data
{
    public function __construct(
        public PlanData $plan,
        public ProfileData $profile,
        public AccountData $account,
        /** @var UpsellData[] */
        public array $upsells = [],
    ) {}
}
