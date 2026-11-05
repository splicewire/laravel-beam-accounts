<?php

namespace Splicewire\Beam\Accounts\Http\Concerns;

use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Throwable;

/**
 * The `accountShell` share: the {@see AccountShellData} the packaged `<AccountShell>` renders, filled by whichever
 * {@see AccountShellProvider} the host bound (the identity-only
 * {@see \Splicewire\Beam\Accounts\Support\IdentityAccountShellProvider} by default).
 *
 * Use it in the host's Inertia middleware (a subclass of `Inertia\Middleware`) and share
 * `'accountShell' => $this->accountShell($request)`. `splicewire/laravel-beam-ux`'s `SharesBeamShell` composes it
 * with the rest of the shell's props.
 *
 * Every starter carried this method by hand, byte for byte (make-surface 01). A host with product data binds its
 * own PROVIDER: the shell's plan, profile metrics and upsells are that host's data (see {@see AccountShellData}).
 * The share is not host data, and neither is the identity-only default.
 */
trait SharesAccountShell
{
    /**
     * The account-shell contract for a signed-in user, through the host-bound provider. Null for a guest, and null
     * when the provider throws, so the shell degrades instead of taking the Inertia response down.
     */
    protected function accountShell(Request $request): ?AccountShellData
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        try {
            return app(AccountShellProvider::class)->shellFor($user);
        } catch (Throwable) {
            return null;
        }
    }
}
