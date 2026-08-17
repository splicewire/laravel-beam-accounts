<?php

namespace Splicewire\Beam\Accounts\Oidc;

use Firebase\JWT\JWT;

/**
 * Mints tower's own signed identity token — the OIDC-issuer half of Workload Identity
 * Federation (or any other OIDC-federation consumer): a short-lived, self-signed JWT that a
 * federation-trusting provider (GCP's STS token-exchange endpoint, most commonly) verifies
 * against {@see \Splicewire\Beam\Accounts\Http\Controllers\OidcDiscoveryController}'s
 * published public key, never against a shared secret. This is what makes the whole scheme
 * keyless from the *consumer's* point of view — GCP trusts tower's issuer URL, not a static
 * credential tower hands it.
 *
 * Short-lived by design (default 5 minutes) — this token is never stored or reused past the
 * single federation exchange it's minted for, so a leaked one has a narrow blast radius.
 */
class IdentityTokenMinter
{
    public function __construct(
        protected SigningKey $key,
        protected string $issuer,
    ) {}

    /**
     * @param  string  $audience  The federation consumer's expected `aud` — for GCP Workload
     *                            Identity Federation, the full workload identity provider resource name
     *                            (`//iam.googleapis.com/projects/.../workloadIdentityPools/.../providers/...`).
     * @param  array<string, mixed>  $claims  Extra claims folded in verbatim, overriding the
     *                                        defaults below if a key collides (e.g. a caller-specific `sub`,
     *                                        if more than one internal identity ever needs to federate under this issuer).
     */
    public function mint(string $audience, array $claims = [], int $ttlSeconds = 300): string
    {
        $now = time();

        $payload = array_merge([
            'iss' => $this->issuer,
            'sub' => $this->issuer,
            'aud' => $audience,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
        ], $claims);

        return JWT::encode($payload, $this->key->privatePem(), 'RS256', $this->key->kid());
    }
}
