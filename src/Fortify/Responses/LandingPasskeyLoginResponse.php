<?php

namespace Splicewire\Beam\Accounts\Fortify\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Splicewire\Beam\Accounts\Landing;

/**
 * laravel/passkeys' web sign-in, landed through {@see Landing} like every other door (ux-walkthrough UX-11, IA-5). The
 * package's own response follows `passkeys.redirect` (Fortify's `home`) and an unchecked intended URL.
 */
class LandingPasskeyLoginResponse implements PasskeyLoginResponse
{
    public function toResponse($request)
    {
        $landing = Landing::for($request->user(), $request->session()->pull('url.intended'));

        return $request->wantsJson()
            ? new JsonResponse(['redirect' => url($landing)], 200)
            : redirect()->to($landing);
    }
}
