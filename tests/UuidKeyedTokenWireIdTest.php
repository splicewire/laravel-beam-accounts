<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Tests\Fixtures\UuidPersonalAccessToken;

/**
 * `G3-ACCOUNTS-TOKEN-ID-CAST` — the token id on the wire is the token's KEY, whatever shape that key
 * is.
 *
 * ## The measurement
 *
 * 2026-09-12, `https://fresh-tower.test`, through the real Tokens roster: Archive issued
 * `DELETE /beam/accounts/tokens/1` and got **500**. `ApiTokenController::present()` read
 * `id: (int) $token->getKey()`, so on a uuid-keyed host — tower, satellite and the flagship all run
 * one — every row in the roster claimed `id: 0` (or `1`, for a key beginning with a digit), and
 * archive / rotate / renew / destroy each addressed whichever token that string happened to find, or
 * none.
 *
 * ## Why the suite did not catch it
 *
 * The package's own default `PersonalAccessToken` is bigint-keyed, and `(int) '7' === 7` — so a
 * bigint host agrees with itself and every existing assertion passes. `AccountApiRoutesTest` even
 * has a bespoke-model case, but it varies the TABLE, not the KEY TYPE. This file varies the key
 * type, through the seam the package already documents for it (`beam.accounts.tokens.model`, "a uuid
 * key is a HOST concern a satellite layers on its own subclass").
 *
 * The class was already internally inconsistent and that is the tell: `present()` emitted an int
 * while `is_current` on the very next line compared `(string) $token->getKey()`, and the sibling
 * particle projection `TokenData::project()` had always emitted `(string) $token->getKey()`. Two
 * transports, two id types, one table. `ImpersonationTest` states the settled rule — a shared shape
 * "holds string keys so a uuid-keyed and a bigint-keyed host share one shape" — and this brings the
 * REST DTOs to it.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    // The uuid-keyed table every splicewire-operated host runs, plus the model that keys it.
    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('tokenable_type');
        $table->uuid('tokenable_id');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->string('provenance')->nullable();
        $table->timestamp('archived_at')->nullable();
        $table->timestamps();
        $table->index(['tokenable_type', 'tokenable_id']);
    });

    config(['beam.accounts.tokens.model' => UuidPersonalAccessToken::class]);

    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();

    $owner = User::create(['name' => 'Owner', 'email' => 'uuid-owner@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());
});

it('mints a token whose wire id is the uuid key, not a coerced integer', function () {
    $created = $this->postJson('/beam/accounts/tokens', ['name' => 'ci'])->assertCreated()->json('data');

    $row = UuidPersonalAccessToken::firstWhere('name', 'ci');

    expect($created['id'])->toBeString()
        ->and($created['id'])->toBe((string) $row->getKey());
});

it('gives every roster row its own id', function () {
    foreach (['one', 'two', 'three'] as $name) {
        $this->postJson('/beam/accounts/tokens', ['name' => $name])->assertCreated();
    }

    $ids = collect($this->getJson('/beam/accounts/tokens')->assertOk()->json('data'))->pluck('id');

    // The defect's exact shape: three rows, three ids, all identical. `(int)` on a uuid is 0 — so
    // this is the assertion that fails first and loudest on the old cast.
    expect($ids)->toHaveCount(3)
        ->and($ids->unique())->toHaveCount(3)
        ->and($ids->every(fn ($id) => is_string($id) && $id !== '0'))->toBeTrue();

    $stored = UuidPersonalAccessToken::query()->pluck('id')->map(strval(...))->sort()->values();
    expect($ids->sort()->values()->all())->toBe($stored->all());
});

it('archives the token the roster addressed, and refuses its bearer afterwards', function () {
    // Two tokens, so "addressed the right one" is a real question rather than a coincidence.
    $keep = $this->postJson('/beam/accounts/tokens', ['name' => 'keep'])->json('data');
    $revoke = $this->postJson('/beam/accounts/tokens', ['name' => 'revoke-me'])->json('data');

    expect(UuidPersonalAccessToken::findToken($revoke['token']))->not->toBeNull();

    $this->deleteJson("/beam/accounts/tokens/{$revoke['id']}")->assertOk();

    // The revoked secret can never match again; the other one is untouched.
    expect(UuidPersonalAccessToken::findToken($revoke['token']))->toBeNull()
        ->and(UuidPersonalAccessToken::findToken($keep['token']))->not->toBeNull()
        ->and(UuidPersonalAccessToken::find($revoke['id'])->archived_at)->not->toBeNull()
        ->and(UuidPersonalAccessToken::find($keep['id'])->archived_at)->toBeNull();

    // Archiving twice is a 409, not a second success — and reaching the 409 at all proves the id
    // still resolves the same row on the second pass.
    $this->deleteJson("/beam/accounts/tokens/{$revoke['id']}")->assertStatus(409);
});

it('rotates and renews through the same uuid id', function () {
    $created = $this->postJson('/beam/accounts/tokens', ['name' => 'rot'])->json('data');

    $renewed = $this->postJson("/beam/accounts/tokens/{$created['id']}/renew", ['expires_in_days' => 30])
        ->assertOk()->json('data');
    expect($renewed['id'])->toBe($created['id']);

    $rotated = $this->postJson("/beam/accounts/tokens/{$created['id']}/rotate")->assertCreated()->json('data');

    // A rotation mints a NEW row, so its id must be a different uuid — not the same coerced 0.
    expect($rotated['id'])->toBeString()
        ->and($rotated['id'])->not->toBe($created['id']);
});

it('permanently deletes only the archived token it addressed', function () {
    $keep = $this->postJson('/beam/accounts/tokens', ['name' => 'keep'])->json('data');
    $gone = $this->postJson('/beam/accounts/tokens', ['name' => 'gone'])->json('data');

    $this->deleteJson("/beam/accounts/tokens/{$gone['id']}/permanent")->assertStatus(422);
    $this->deleteJson("/beam/accounts/tokens/{$gone['id']}")->assertOk();
    $this->deleteJson("/beam/accounts/tokens/{$gone['id']}/permanent")->assertOk();

    expect(UuidPersonalAccessToken::find($gone['id']))->toBeNull()
        ->and(UuidPersonalAccessToken::find($keep['id']))->not->toBeNull();
});

it('declares both REST token DTOs with a string id', function () {
    // The wire TYPE, not just the runtime value: `ApiTokenData`/`CreatedTokenData` are what the
    // TypeScript projection is generated from, so an int here is an int in five starter bundles.
    foreach ([
        Splicewire\Beam\Accounts\Data\ApiTokenData::class,
        Splicewire\Beam\Accounts\Data\CreatedTokenData::class,
    ] as $data) {
        $type = (new ReflectionProperty($data, 'id'))->getType();

        expect($type?->getName())->toBe('string', "{$data}::\$id must be a string on the wire");
    }
});
