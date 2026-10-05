<?php

namespace Splicewire\Beam\Accounts\Fortify\Responses;

use Laravel\Fortify\Http\Responses\RedirectAsIntended;
use Splicewire\Beam\Accounts\Landing;

/**
 * Fortify's generic "back to where you were going" redirect (the email-verification prompt and resend, for a user who
 * is already verified), landed through {@see Landing} (ux-walkthrough UX-11, IA-5) instead of `fortify.home`.
 */
class LandingRedirectAsIntended extends RedirectAsIntended
{
    public function toResponse($request)
    {
        return redirect()->to(Landing::for($request->user(), $request->session()->pull('url.intended')));
    }
}
