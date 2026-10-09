<?php

use Splicewire\Beam\Accounts\Data\ApiTokenData;
use Splicewire\Beam\Accounts\Data\Pages\SecurityPasskeyData;
use Splicewire\Beam\Accounts\Data\PasskeyData;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyController;

it('publishes token and passkey output DTOs with only camel wire keys', function () {
    $token = new ApiTokenData(
        id: 'token-1',
        name: 'CI',
        provenance: TokenProvenance::Api,
        abilities: ['tokens.read'],
        createdAt: '2026-10-09T00:00:00Z',
        lastUsedAt: null,
        expiresAt: null,
        archivedAt: null,
        isCurrent: true,
    );
    $passkey = new PasskeyData(1, 'Laptop', null, '2026-10-09T00:00:00Z');
    $pagePasskey = new SecurityPasskeyData(1, 'Laptop', null, 'today', null);

    expect($token->toArray())->toHaveKeys(['createdAt', 'lastUsedAt', 'expiresAt', 'archivedAt', 'isCurrent'])
        ->not->toHaveKeys(['created_at', 'last_used_at', 'expires_at', 'archived_at', 'is_current'])
        ->and($passkey->toArray())->toHaveKeys(['lastUsedAt', 'createdAt'])
        ->not->toHaveKeys(['last_used_at', 'created_at'])
        ->and($pagePasskey->toArray())->toHaveKeys(['createdAtDiff', 'lastUsedAtDiff'])
        ->not->toHaveKeys(['created_at_diff', 'last_used_at_diff']);
});

it('presents the hand-built passkey response with the same camel contract', function () {
    $present = new ReflectionMethod(PasskeyController::class, 'present');
    $wire = $present->invoke(new PasskeyController, (object) [
        'id' => 1,
        'name' => 'Laptop',
        'last_used_at' => null,
        'created_at' => now(),
    ]);

    expect($wire)->toHaveKeys(['lastUsedAt', 'createdAt'])
        ->not->toHaveKeys(['last_used_at', 'created_at']);
});
