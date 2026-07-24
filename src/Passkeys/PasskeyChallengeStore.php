<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Passkeys;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Server-side challenge store for stateless passkey ceremonies.
 *
 * WebAuthn requires the challenge issued at options-time to be validated against the
 * assertion/attestation at verify-time — and it must be SERVER-bound, never trusted from the
 * client. A token-based host's login flow is stateless (bearer tokens, no session), so we stash
 * the generated options in the cache under an opaque single-use handle, hand the handle to the
 * browser, and pull it back (deleting it) when the ceremony returns. Short TTL bounds replay.
 *
 * Companion to {@see PasskeyAuthenticator}: the authenticator builds/validates the options, this
 * store holds them across the stateless round-trip. Neither takes a session, guard, or host model
 * dependency, so any token-based beam host can drive the same ceremonies.
 */
class PasskeyChallengeStore
{
    private const TTL_SECONDS = 300;

    private const PREFIX = 'passkey:challenge:';

    /**
     * Stash the serialized options and return the opaque handle for the browser to echo back.
     *
     * @param  object  $options  A PublicKeyCredentialRequestOptions or …CreationOptions.
     */
    public function put(object $options, string $optionsClass): string
    {
        $handle = (string) Str::uuid();

        Cache::put(self::PREFIX.$handle, [
            'class' => $optionsClass,
            'json' => WebAuthn::toJson($options),
        ], self::TTL_SECONDS);

        return $handle;
    }

    /**
     * Pull and delete the options for a handle, deserialized back to the original type.
     * Returns null when the handle is unknown/expired/already used.
     */
    public function pull(string $handle): ?object
    {
        $entry = Cache::pull(self::PREFIX.$handle);

        if (! is_array($entry) || ! isset($entry['class'], $entry['json'])) {
            return null;
        }

        return WebAuthn::fromJson($entry['json'], $entry['class']);
    }
}
