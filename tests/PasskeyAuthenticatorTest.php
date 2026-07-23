<?php

declare(strict_types=1);

use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use Splicewire\Beam\Accounts\Passkeys\PasskeyAuthenticator;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * The seam is exercised with a mocked VerifyPasskey — the WebAuthn crypto itself is
 * laravel/passkeys' (and web-auth/webauthn-lib's) responsibility, tested there. What THIS
 * seam owns, and what these tests pin, is the guard-agnostic contract: a valid assertion
 * resolves the user, an invalid one fails soft, and NOTHING ever logs in.
 */
function makeAuthenticator(array $overrides = []): PasskeyAuthenticator
{
    return new PasskeyAuthenticator(
        $overrides['verificationOptions'] ?? Mockery::mock(GenerateVerificationOptions::class),
        $overrides['registrationOptions'] ?? Mockery::mock(GenerateRegistrationOptions::class),
        $overrides['verifyPasskey'] ?? Mockery::mock(VerifyPasskey::class),
        $overrides['storePasskey'] ?? Mockery::mock(StorePasskey::class),
    );
}

// A structurally-real PublicKeyCredential (assertion shape). VerifyPasskey is mocked, so the
// values need only deserialize — the crypto is never exercised here.
function dummyAssertion(): PublicKeyCredential
{
    $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

    // Minimal well-formed authenticatorData: 32-byte rpIdHash + flags(UP only) + 4-byte counter.
    $authData = str_repeat("\x00", 32).chr(0x01).str_repeat("\x00", 4);

    $json = json_encode([
        'id' => $b64('credential-id'),
        'rawId' => $b64('credential-id'),
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => $b64('{"type":"webauthn.get","challenge":"'.$b64('challenge-bytes').'","origin":"https://test"}'),
            'authenticatorData' => $b64($authData),
            'signature' => $b64('signature'),
            'userHandle' => $b64('user-handle'),
        ],
    ]);

    return WebAuthn::fromJson($json, PublicKeyCredential::class);
}

function dummyOptions(): PublicKeyCredentialRequestOptions
{
    return PublicKeyCredentialRequestOptions::create(challenge: random_bytes(32));
}

it('resolves the user behind a valid assertion without logging anyone in', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $passkey = new Passkey;
    $passkey->setRelation('user', $user);

    $verify = Mockery::mock(VerifyPasskey::class);
    $verify->shouldReceive('__invoke')->once()->andReturn($passkey);

    $resolved = makeAuthenticator(['verifyPasskey' => $verify])
        ->resolveUserFromAssertion(dummyAssertion(), dummyOptions());

    expect($resolved)->toBe($user);
    // The seam's whole reason for being: it never touches a guard.
    expect(auth()->check())->toBeFalse();
});

it('returns null (fails soft) on an invalid assertion and still does not log in', function () {
    $verify = Mockery::mock(VerifyPasskey::class);
    $verify->shouldReceive('__invoke')->once()->andThrow(InvalidPasskeyException::make('bad'));

    $resolved = makeAuthenticator(['verifyPasskey' => $verify])
        ->resolveUserFromAssertion(dummyAssertion(), dummyOptions());

    expect($resolved)->toBeNull();
    expect(auth()->check())->toBeFalse();
});

it('delegates login options to the verification option builder', function () {
    $options = dummyOptions();

    $builder = Mockery::mock(GenerateVerificationOptions::class);
    $builder->shouldReceive('__invoke')->once()->with(null)->andReturn($options);

    expect(makeAuthenticator(['verificationOptions' => $builder])->loginOptions())->toBe($options);
});
