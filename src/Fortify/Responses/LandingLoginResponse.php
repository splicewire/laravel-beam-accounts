<?php

namespace Splicewire\Beam\Accounts\Fortify\Responses;

use Laravel\Fortify\Http\Responses\LoginResponse as FortifyResponse;
use Splicewire\Beam\Accounts\Landing;

/**
 * Fortify's login response, landed through {@see Landing} (ux-walkthrough UX-11, IA-5): a JSON caller gets Fortify's own
 * answer; a browser goes to Landing::for(user, the pulled intended URL), never `fortify.home` and never an unchecked
 * intended URL.
 */
class LandingLoginResponse extends FortifyResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return parent::toResponse($request);
        }

        return redirect()->to(Landing::for($request->user(), $request->session()->pull('url.intended')));
    }
}
