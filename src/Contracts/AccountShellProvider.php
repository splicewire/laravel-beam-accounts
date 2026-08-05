<?php

namespace Splicewire\Beam\Accounts\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Splicewire\Beam\Accounts\Support\NullAccountShellProvider;

/**
 * The host seam that POPULATES the account-shell shape. beam-accounts projects the
 * {@see AccountShellData} SHAPE and provides this bindable contract; the host binds an implementation
 * that reads its own product data (plan tier/credits, public-profile metrics, upsell CTAs, a payment
 * DISPLAY LABEL) and returns the filled shape.
 *
 * The package owns no billing: it makes no charge, holds no card data, and ships no monetization copy.
 * A host that binds no provider gets the {@see NullAccountShellProvider}
 * default, which returns `null` — so the shell degrades gracefully (renders nothing) rather than 500.
 */
interface AccountShellProvider
{
    /**
     * The account shell for the given user, or null when the host provides none (a guest, or an
     * unbound/no-op host).
     */
    public function shellFor(?Authenticatable $user): ?AccountShellData;
}
