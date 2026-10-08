<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Accounts\Auth\AuthTokenFactory;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\LoginResponseData;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Landing;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;

/** Exchange an already-authenticated first-party web session for the SPA's short-lived bearer. */
class SessionTokenController extends Controller
{
    #[ResponseFromData(LoginResponseData::class)]
    public function __invoke(Request $request): ResponseBody
    {
        $user = $request->user();
        $token = AuthTokenFactory::mint(
            $user,
            $request->userAgent() ?? '',
            TokenProvenance::Session,
            false,
        );

        return ResponseBody::from([
            'data' => AuthUserData::fromUser($user, $token->plainTextToken),
            'meta' => ['landing' => Landing::for($user)],
        ]);
    }
}
