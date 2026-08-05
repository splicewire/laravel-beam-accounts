<?php

use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Accounts\Models\ShareLink;
use Splicewire\Beam\Accounts\Sharing\ShareLinks;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * Tracer 05 — the reusable ShareLink capability primitive: mint → validate → redeem → revoke,
 * honouring expiry, revocation, and the use cap; plus the minter-ownership `manageShareLinks` gate.
 */
beforeEach(function () {
    $this->links = app(ShareLinks::class);
    $this->creator = User::create(['name' => 'Creator', 'email' => 'creator@example.test', 'password' => 'x']);
});

it('mints a valid link that resolves by token', function () {
    $link = $this->links->create($this->creator, 'composition:abc');

    expect($link->token)->not->toBeEmpty()
        ->and($link->scope)->toBe('composition:abc')
        ->and($link->created_by)->toBe((string) $this->creator->getKey())
        ->and($link->isValid())->toBeTrue()
        ->and($this->links->validate($link->token)->is($link))->toBeTrue();
});

it('does not resolve an unknown token', function () {
    expect($this->links->validate('nope'))->toBeNull();
});

it('treats an expired link as invalid', function () {
    $link = $this->links->create($this->creator, 'composition:abc', expiresAt: now()->subMinute());

    expect($link->isExpired())->toBeTrue()
        ->and($link->isValid())->toBeFalse()
        ->and($this->links->validate($link->token))->toBeNull();
});

it('treats a revoked link as invalid', function () {
    $link = $this->links->create($this->creator, 'composition:abc');
    $this->links->revoke($link);

    expect($link->isRevoked())->toBeTrue()
        ->and($link->fresh()->isValid())->toBeFalse()
        ->and($this->links->validate($link->token))->toBeNull();
});

it('honours the use cap — invalid once max_uses is reached', function () {
    $link = $this->links->create($this->creator, 'composition:abc', maxUses: 2);

    expect($link->isValid())->toBeTrue();
    $this->links->redeem($link);           // use_count 1
    expect($link->fresh()->isValid())->toBeTrue();
    $this->links->redeem($link->fresh());  // use_count 2 == max
    expect($link->fresh()->isValid())->toBeFalse()
        ->and($this->links->validate($link->token))->toBeNull();
});

it('revoke is idempotent', function () {
    $link = $this->links->create($this->creator, 'composition:abc');
    $first = $this->links->revoke($link)->revoked_at;
    $second = $this->links->revoke($link->fresh())->revoked_at;

    expect($first->equalTo($second))->toBeTrue();
});

it('gates management to the minter', function () {
    $link = $this->links->create($this->creator, 'composition:abc');
    $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']);

    expect(Gate::forUser($this->creator)->allows('manageShareLinks', $link))->toBeTrue()
        ->and(Gate::forUser($other)->allows('manageShareLinks', $link))->toBeFalse();
});
