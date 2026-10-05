<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\TransientToken;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;

/**
 * `POST api/v1/logout` — Sign out on the SERVER (tower-is-splicewire D3′; INTERSECTION §5). A client clearing its own
 * state leaves the token it signed in with live until it expires; this ends the sign-in where it was issued.
 *
 * - **The token the caller presented** is revoked, and only that one: a user's other personal access tokens (a CLI, a
 *   satellite pairing) are theirs to manage on the tokens surface, never collateral of a browser sign-out. A caller
 *   authenticated by SESSION carries Sanctum's {@see TransientToken}, which is not a stored row, so there is nothing
 *   to delete.
 * - **The session**, when the request has one (`statefulApi` on, or a `web`-group mount), is logged out of the web
 *   guard, invalidated and given a fresh CSRF token, as Fortify's own logout does.
 *
 * Mounted under `auth:sanctum`, so an already-revoked token is refused with a plain 401 before this runs: safe, and
 * the client treats 401 as signed out anyway (D3′: only 401 and 419 mean signed out).
 */
class LogoutController extends Controller
{
    public function logout(Request $request): ResponseBody
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof HasAbilities && ! $token instanceof TransientToken && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ResponseBody::from(['message' => 'Signed out.']);
    }
}
