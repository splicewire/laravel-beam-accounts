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
    // Sanctum's table shape, keyed the way every splicewire-operated host keys it — by uuid.
    // The minter writes through the query builder, never a Sanctum class, so beam-accounts keeps
    // Sanctum an opt-in dependency and the shipped .stub stays the shipped .stub.
    Schema::create('personal_access_tokens', function (Blueprint $table) {
        $table->uuid('id')->primary();
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

/**
 * Re-key the table the way Sanctum's own shipped migration does — bigint. Not a supported
 * splicewire configuration (seed-provisioning-cleanup 01 dropped the `int|string` widening that
 * served it), but two live callers still pass an int constant: `~/Herd/numero`'s
 * `SplicewireEngineKeySeeder::TOKEN_ID` and `laravel-satellite`'s `MintEngineKeyCommand` default.
 * Neither file declares `strict_types`, so PHP coerces the int to the identical numeric string and
 * the identical bearer — this fixture is what proves that, rather than leaving it argued.
 */
function useBigintKeyedTokensTable(): void
{
    Schema::dropIfExists('personal_access_tokens');

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
}

/**
 * The pinned satellite token id. A uuid5 in production (`SecondPartyTenantSeeder::tokenUuid()`);
 * a fixed literal here, because the whole point of the primitive is that the id is an INPUT.
 */
const PINNED_TOKEN_ID = '2b1e7c9a-3f4d-5a6b-8c7d-9e0f1a2b3c4d';

function makeToken(array $overrides = []): DeterministicToken
{
    return new DeterministicToken(
        id: $overrides['id'] ?? PINNED_TOKEN_ID,
        plaintext: $overrides['plaintext'] ?? 'numeroSatelliteServiceToken00000000000v1',
        tokenableType: $overrides['tokenableType'] ?? 'user',
        tokenableId: $overrides['tokenableId'] ?? 'owner-uuid',
        name: $overrides['name'] ?? 'numero-satellite',
    );
}

it('declares a string (uuid) primary key, with no int widening left', function () {
    // The narrowing IS the deliverable (seed-provisioning-cleanup 01), and it is invisible to every
    // behavioural test in this file: `int|string` and `string` accept the same inputs in coercive
    // mode, so only the declaration itself can witness it.
    $id = (new ReflectionClass(DeterministicToken::class))->getConstructor()->getParameters()[0];

    expect($id->getName())->toBe('id')
        ->and((string) $id->getType())->toBe('string');
});

it('derives the bearer as a pure function of id and plaintext', function () {
    // Same inputs → same bearer, computed with no DB, no randomness. This IS the shared credential.
    expect(makeToken()->bearer())->toBe(PINNED_TOKEN_ID.'|numeroSatelliteServiceToken00000000000v1')
        ->and(makeToken()->bearer())->toBe(makeToken()->bearer());

    // The stored hash is likewise a pure function of the plaintext.
    expect(makeToken()->hash())->toBe(hash('sha256', 'numeroSatelliteServiceToken00000000000v1'));
});

it('mints one reset-surviving row and returns the bearer', function () {
    $bearer = makeToken()->mint();

    expect($bearer)->toBe(PINNED_TOKEN_ID.'|numeroSatelliteServiceToken00000000000v1');

    $row = DB::table('personal_access_tokens')->where('id', PINNED_TOKEN_ID)->first();
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
        ->and(DB::table('personal_access_tokens')->where('id', PINNED_TOKEN_ID)->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1);
});

it('mints against a uuid-keyed table storing the pinned uuid verbatim as the row id', function () {
    // The property under test is that NOTHING between the caller and the row generates or
    // coerces the key. `mint()` writes through the query builder, so Eloquent's
    // `HasUniqueIds::setUniqueIds()` — which only generates `if (empty($this->{$column}))` —
    // never even runs; the pinned value is the only candidate the insert ever sees.
    $uuid = PINNED_TOKEN_ID;

    makeToken(['id' => $uuid])->mint();

    $rows = DB::table('personal_access_tokens')->get();
    expect($rows)->toHaveCount(1);

    // Not a freshly generated uuid, not `0` from an int cast, not null.
    expect($rows->first()->id)->toBe($uuid)
        ->and($rows->first()->token)->toBe(hash('sha256', 'numeroSatelliteServiceToken00000000000v1'));
});

it('returns a bearer whose id half is the pinned uuid, so it can find its own row', function () {
    $uuid = PINNED_TOKEN_ID;

    $bearer = makeToken(['id' => $uuid])->mint();

    expect($bearer)->toBe($uuid.'|numeroSatelliteServiceToken00000000000v1');

    // Walk the bearer the way Sanctum's findToken() does: split on the pipe, look the row up
    // by primary key, then compare the hashed plaintext. A coerced id breaks this lookup.
    [$id, $plaintext] = explode('|', $bearer, 2);
    $row = DB::table('personal_access_tokens')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and($row->token)->toBe(hash('sha256', $plaintext));
});

it('is idempotent on a uuid-keyed table — one row, same bearer', function () {
    $uuid = PINNED_TOKEN_ID;

    $first = makeToken(['id' => $uuid])->mint();
    $second = makeToken(['id' => $uuid])->mint();

    expect($second)->toBe($first)
        ->and(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and(DB::table('personal_access_tokens')->where('id', $uuid)->count())->toBe(1);
});

it('coerces a live int caller onto the bigint default it still uses', function () {
    // NOT a supported configuration — `string` is the declared type now. It is asserted because two
    // live callers pass an int constant (`~/Herd/numero`'s `SplicewireEngineKeySeeder::TOKEN_ID`,
    // `laravel-satellite`'s `MintEngineKeyCommand` default) and neither file declares
    // `strict_types`, so PHP coerces. The narrowing is therefore a narrowing and not an outage —
    // and adding `declare(strict_types=1)` to either file would make it one.
    useBigintKeyedTokensTable();

    $bearer = makeToken(['id' => '990003'])->mint();

    expect($bearer)->toBe('990003|numeroSatelliteServiceToken00000000000v1');

    expect(DB::table('personal_access_tokens')->count())->toBe(1)
        ->and((int) DB::table('personal_access_tokens')->value('id'))->toBe(990003);

    // Same row as the int-id mint would touch: re-minting with the int does not add a second.
    makeToken(['id' => 990003])->mint();
    expect(DB::table('personal_access_tokens')->count())->toBe(1);
});

it('two independent instances mint the identical credential — no central authority needed', function () {
    // The satellite mints; the engine, given only the shared (id, plaintext), mints the same.
    $satelliteBearer = makeToken()->mint();
    $engineBearer = makeToken()->bearer(); // engine derives it without ever seeing the satellite's row

    expect($engineBearer)->toBe($satelliteBearer);
});

it('keeps the host-facing mint-key command off by default (module default-off)', function () {
    // The primitive is always usable in PHP; only the console door is gated, and it is
    // off unless a host opts in via beam.accounts.keys.enabled.
    expect(array_keys($this->app[Kernel::class]->all()))
        ->not->toContain('splicewire:beam:accounts:mint-key');
});
