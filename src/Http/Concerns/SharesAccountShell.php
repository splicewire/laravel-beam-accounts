<?php

namespace Splicewire\Beam\Accounts\Http\Concerns;

use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Contracts\AccountShellProvider;
use Splicewire\Beam\Accounts\Data\AccountShellData;
use Throwable;

/**
 * The `accountShell` share: the {@see AccountShellData} the packaged `<AccountShell>` renders, filled by whichever
 * {@see AccountShellProvider} the host bound (the {@see \Splicewire\Beam\Accounts\Support\NullAccountShellProvider}
 * by default).
 *
 * Use it in the host's Inertia middleware (a subclass of `Inertia\Middleware`) and share
 * `'accountShell' => $this->accountShell($request)`. `splicewire/laravel-beam-ux`'s `SharesBeamShell` composes it
 * with the rest of the shell's props.
 *
 * Every starter carried this method by hand, byte for byte (make-surface 01). The PROVIDER stays the host's: the
 * shell's plan, profile and account values are its own product data (see {@see AccountShellData}); the share is not.
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
