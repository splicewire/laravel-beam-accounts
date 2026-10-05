<?php

namespace Splicewire\Beam\Accounts\Fortify\Responses;

use Laravel\Fortify\Http\Responses\VerifyEmailResponse as FortifyResponse;
use Splicewire\Beam\Accounts\Landing;

/**
 * Fortify's email-verified response, landed through {@see Landing} (ux-walkthrough UX-11, IA-5). Like Fortify, a landing
 * that is not a kept intended URL carries `?verified=1` so the page can say so.
 */
class LandingVerifyEmailResponse extends FortifyResponse
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return parent::toResponse($request);
        }

        $intended = Landing::safeIntended($request->session()->pull('url.intended'));

        return redirect()->to($intended ?? Landing::for($request->user()).'?verified=1');
    }
}
