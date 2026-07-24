<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Passkeys;

use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredential;

/**
 * Shared plumbing for a host's passkey controllers: pull the server-bound options for a handle
 * (typed, single-use) and deserialize the browser credential. Keeps the login (assertion) and
 * management (attestation) ceremonies from repeating the handle→pull→deserialize dance.
 *
 * Guard/host-agnostic — it only touches {@see PasskeyChallengeStore} and the WebAuthn marshalling,
 * so the same trait serves a session host and a token host alike.
 */
trait ResolvesPasskeyCeremonies
{
    /**
     * Pull the stored options for a handle, or null when the handle is unknown/expired or the
     * stored options aren't of the expected type.
     *
     * @param  class-string  $expected
     */
    protected function pullOptions(PasskeyChallengeStore $store, string $handle, string $expected): ?object
    {
        $options = $store->pull($handle);

        return $options instanceof $expected ? $options : null;
    }

    /**
     * Deserialize the browser's PublicKeyCredential (assertion or attestation) from request JSON.
     *
     * @param  array<string, mixed>  $credential
     */
    protected function credentialFromRequest(array $credential): PublicKeyCredential
    {
        return WebAuthn::fromJson(json_encode($credential), PublicKeyCredential::class);
    }
}
