<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Http\Request;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Sharing\ShareLinks;
use Splicewire\Beam\Accounts\Sharing\ShareLinkScopes;

/**
 * The reusable link-only front door (ADR-0009, tracer 06): `GET /s/{token}`. Validates the
 * token (honoring expiry / revocation / use-cap), dispatches the scope to the host's handler
 * (see {@see ShareLinkScopes}), and counts a use only on a successful resolution. Any host
 * that mints {@see ShareLink}s gets this plumbing for free;
 * the host supplies only what a scope resolves TO.
 */
class ShareLinkController
{
    public function resolve(string $token, Request $request, ShareLinks $links, ShareLinkScopes $scopes): mixed
    {
        $link = $links->validate($token);

        abort_if($link === null, 404); // unknown / expired / revoked / exhausted

        // Resolve the target FIRST — a handler may 404 (e.g. the resource was deleted), in
        // which case no use is counted. Only a genuine resolution redeems.
        $response = $scopes->resolve($link, $request);

        $links->redeem($link);

        return $response;
    }
}
