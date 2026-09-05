<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\LoginInputData;
use Splicewire\Beam\Accounts\Data\LoginResponseData;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;

/**
 * Login (first-party SPA session-bootstrap). Relocated down from Tower on the canonical Data shape
 * (HTTP-07); the primitives it consumes (`AuthTokenFactory`, `TokenProvenance`, `AuthUserData`) already
 * live in beam-accounts, so what was an up-dependency is now a same-package call.
 *
 * Deliberately NOT published to the generated API reference (excluded in the host's `config/scribe.php`).
 * This is the password grant that bootstraps the first-party SPA session only — machine/integration
 * clients authenticate with a pre-minted Personal Access Token, never a password. See ADR-0108.
 *
 * The token mint (formerly a serialization side-effect in `AuthUserResource`) now lives HERE, ahead of
 * projecting: mint a `Session`-provenance token via {@see AuthTokenFactory}, then hand its plaintext to
 * {@see AuthUserData::fromUser} — so the response Data is a pure projection with no mint inside it (asset
 * 11 §3.1; the factory is the anti-drift seam it shares with the passkey mint).
 */
class LoginController extends Controller
{
    /**
     * The `Request` rides alongside the typed `LoginInputData` for the User-Agent (token name) and the
     * remember flag; `LoginInputData` carries + validates the credentials.
     */
    #[RequestFromData(LoginInputData::class)]
    #[ResponseFromData(LoginResponseData::class)]
    #[ResponseFromData(LoginResponseData::class, status: 401)]
    public function login(LoginInputData $input, Request $request): ResponseBody
    {
        if (Auth::attempt(['email' => $input->email, 'password' => $input->password])) {
            $user = Auth::user();

            $token = AuthTokenFactory::mint(
                $user,
                $request->userAgent() ?? '',
                TokenProvenance::Session,
                (bool) $input->remember,
            );

            return ResponseBody::from(['data' => AuthUserData::fromUser($user, $token->plainTextToken)]);
        }

        $body = ResponseBody::from(['message' => 'Unauthorized.']);
        $body->success = false;
        $body->statusCode = Response::HTTP_UNAUTHORIZED;

        return $body;
    }
}
