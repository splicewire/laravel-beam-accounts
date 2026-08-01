<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Keys\DeterministicToken;

/**
 * The per-host key primitive: a deterministic, reset-surviving PAT minter. The load-bearing
 * property is reproducibility WITHOUT a central authority — the same (id, plaintext) yields
 * the same bearer and the same stored hash on every host, so a satellite and the engine can
 * each mint the credential they share with no handshake and no central store.
 */
beforeEach(function () {
    // Sanctum's table shape — the minter writes to it via the query builder, never a
    // Sanctum class, so beam-accounts keeps Sanctum an opt-in dependency.
    Schema::create('personal_access_tokens', function (Blueprint $table) {
        $table->id();
        $table->string('tokenable_type');
        $table->string('tokenable_id');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
});

function makeToken(array $overrides = []): DeterministicToken
{
    return new DeterministicToken(
        id: $overrides['id'] ?? 990003,
        plaintext: $overrides['plaintext'] ?? 'numeroSatelliteServiceToken00000000000v1',
        tokenableType: $overrides['tokenableType'] ?? 'user',
        tokenableId: $overrides['tokenableId'] ?? 'owner-uuid',
        name: $overrides['name'] ?? 'numero-satellite',
    );
}

it('derives the bearer as a pure function of id and plaintext', function () {
    // Same inputs → same bearer, computed with no DB, no randomness. This IS the shared credential.
    expect(makeToken()->bearer())->toBe('990003|numeroSatelliteServiceToken00000000000v1')
        ->and(makeToken()->bearer())->toBe(makeToken()->bearer());

    // The stored hash is likewise a pure function of the plaintext.
    expect(makeToken()->hash())->toBe(hash('sha256', 'numeroSatelliteServiceToken00000000000v1'));
});

it('mints one reset-surviving row and returns the bearer', function () {
    $bearer = makeToken()->mint();

    expect($bearer)->toBe('990003|numeroSatelliteServiceToken00000000000v1');

    $row = DB::table('personal_access_tokens')->where('id', 990003)->first();
    expect($row->tokenable_type)->toBe('user')
        ->and($row->tokenable_id)->toBe('owner-uuid')
        ->and($row->name)->toBe('numero-satellite')
        ->and($row->token)->toBe(hash('sha256', 'numeroSatelliteServiceToken00000000000v1'))
        ->and(json_decode($row->abilities, true))->toBe(['*']);
});

it('is idempotent — re-minting never duplicates and never changes the credential', function () {
    $first = makeToken()->mint();
    $second = makeToken()->mint();

    expect($second)->toBe($first)
        ->and(DB::table('personal_access_tokens')->where('id', 990003)->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);
});

it('accepts a string (uuid) id — folded verbatim into the bearer', function () {
    // Hosts that key personal_access_tokens by UUID (not Sanctum's default bigint) pass a
    // string id; it composes the bearer exactly like an int, no coercion.
    $uuid = '2b1e7c9a-3f4d-5a6b-8c7d-9e0f1a2b3c4d';

    expect(makeToken(['id' => $uuid])->bearer())
        ->toBe($uuid.'|numeroSatelliteServiceToken00000000000v1');
});

it('two independent instances mint the identical credential — no central authority needed', function () {
    // The satellite mints; the engine, given only the shared (id, plaintext), mints the same.
    $satelliteBearer = makeToken()->mint();
    $engineBearer = makeToken()->bearer(); // engine derives it without ever seeing the satellite's row

    expect($engineBearer)->toBe($satelliteBearer);
});

it('keeps the host-facing mint-key command off by default (module default-off)', function () {
    // The primitive is always usable in PHP; only the console door is gated, and it is
    // off unless a host opts in via splicewire.account.keys.enabled.
    expect(array_keys($this->app[Kernel::class]->all()))
        ->not->toContain('splicewire:beam:accounts-mint-key');
});
