<?php

namespace Splicewire\Beam\Accounts\Http\Controllers;

use Illuminate\Routing\Controller;
use Splicewire\Beam\Accounts\Oidc\SigningKey;

/**
 * Serves the two public endpoints an OIDC-federation consumer needs to verify tower's
 * self-signed identity tokens ({@see \Splicewire\Beam\Accounts\Oidc\IdentityTokenMinter}) —
 * discovery, and the JWKS it points at. Both are unauthenticated by design: a JWKS
 * endpoint's entire job is being publicly fetchable, and there is no private key material
 * here, only the public modulus/exponent.
 */
class OidcDiscoveryController extends Controller
{
    public function __construct(protected SigningKey $key) {}

    public function discovery()
    {
        $issuer = rtrim((string) config('beam.accounts.oidc.issuer'), '/');

        return response()->json([
            'issuer' => $issuer,
            'jwks_uri' => "{$issuer}/.well-known/jwks.json",
            'response_types_supported' => ['id_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ]);
    }

    public function jwks()
    {
        return response()->json(['keys' => [$this->key->jwk()]]);
    }
}
