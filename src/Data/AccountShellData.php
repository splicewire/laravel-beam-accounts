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
 * card data.
 *
 * The plan is OPTIONAL: `null` means nothing on this host owns plans (commerce contributes one when it
 * is installed), and the packaged `<AccountShell>` then hides the plan section. A host that binds no
 * provider gets {@see \Splicewire\Beam\Accounts\Support\IdentityAccountShellProvider}, which fills
 * only the user's own identity (handle, initials, email) and no plan.
 */
class AccountShellData extends Data
{
    public function __construct(
        public ?PlanData $plan,
        public ProfileData $profile,
        public AccountData $account,
        /** @var UpsellData[] */
        public array $upsells = [],
    ) {}
}
