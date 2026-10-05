<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * `GET /logout`: a confirm page that POSTs (ux-walkthrough UX-11, IA-5). Signing out is never a GET, which a link, a
 * prefetch or an image tag could trigger; a guest has nothing to sign out of and is sent home.
 */
class LogoutConfirmController
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user() === null) {
            return redirect('/');
        }

        return view('beam-accounts::logout-confirm', [
            'action' => Route::has('logout') ? route('logout') : url('/logout'),
            'appName' => (string) config('app.name'),
        ]);
    }
}
