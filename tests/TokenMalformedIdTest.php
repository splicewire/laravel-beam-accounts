<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Http\Controllers\Account\ApiTokenController;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Accounts\Tests\Fixtures\UuidPersonalAccessToken;

/**
 * `G3-ACCOUNTS-TOKEN-MALFORMED-ID` — a malformed token id in the URL is a 404, never a SQLSTATE.
 *
 * ## The measurement
 *
 * 2026-09-12, `https://fresh-tower.test`: `DELETE /beam/accounts/tokens/999999` (tower is
 * uuid-keyed) answered **500** — `SQLSTATE[22P02] invalid input syntax for type uuid: "999999"` —
 * because `ApiTokenController::findOwnToken()` queried the configured token model's key column with
 * the raw route segment, unvalidated. The same failure mode runs the other way on an int-keyed host:
 * a non-numeric segment reaching a bigint `WHERE id = ?` throws rather than missing cleanly.
 *
 * The repair validates the id against the token model's key SHAPE — read off
 * `BeamAccounts::tokenModel()`, never assumed — before the query, in the one helper
 * (`findOwnToken()`) that `renew`, `rotate`, `archive` and `destroy` all route through.
 *
 * ## Why the HTTP-level cases below are not the whole proof
 *
 * This suite's connection is sqlite (`:memory:`), which does not enforce column type the way
 * Postgres does — a string compared against a `uuid` column here just misses, so the HTTP-level
 * cases would report 404 "for free" even on the unfixed controller and would not have caught the
 * real defect. The `idMatchesKeyShape()` reflection cases below are the ones that are actually
 * red against the pre-fix controller (the method did not exist at all); the HTTP cases stay as the
 * documented, engine-independent behavioural contract.
 */
function loginTeamOwner(): void
{
    $owner = User::create(['name' => 'Owner', 'email' => 'malformed-owner@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());
}

describe('uuid-keyed host', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ValidateCsrfToken::class);

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

        loginTeamOwner();
    });

    it('answers 404 for a numeric id, never a SQLSTATE 500', function () {
        $this->deleteJson('/beam/accounts/tokens/999999')
            ->assertNotFound();
    });

    it('answers 404 for a non-uuid string id on archive/rotate/renew/destroy', function () {
        $this->deleteJson('/beam/accounts/tokens/999999')->assertNotFound();
        $this->postJson('/beam/accounts/tokens/999999/rotate')->assertNotFound();
        $this->postJson('/beam/accounts/tokens/999999/renew', ['expires_in_days' => 30])->assertNotFound();
        $this->deleteJson('/beam/accounts/tokens/999999/permanent')->assertNotFound();
    });

    it('still finds a real row by its well-formed uuid', function () {
        $created = $this->postJson('/beam/accounts/tokens', ['name' => 'ci'])->assertCreated()->json('data');

        $this->deleteJson("/beam/accounts/tokens/{$created['id']}")->assertOk();
    });

    it('answers 404 for a well-formed but non-existent uuid', function () {
        $this->deleteJson('/beam/accounts/tokens/'.(string) Illuminate\Support\Str::uuid())
            ->assertNotFound();
    });
});

describe('int-keyed host (the package default)', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->createPersonalAccessTokensSchema();

        Route::splicewireAccountApiRoutes();
        Route::getRoutes()->refreshNameLookups();

        loginTeamOwner();
    });

    it('answers 404 for a non-numeric id, never a driver-level error', function () {
        $this->deleteJson('/beam/accounts/tokens/not-a-real-id')
            ->assertNotFound();
    });

    it('still finds a real row by its numeric id', function () {
        $created = $this->postJson('/beam/accounts/tokens', ['name' => 'ci'])->assertCreated()->json('data');

        $this->deleteJson("/beam/accounts/tokens/{$created['id']}")->assertOk();
    });

    it('answers 404 for a well-formed but non-existent numeric id', function () {
        $this->deleteJson('/beam/accounts/tokens/999999')->assertNotFound();
    });
});

describe('idMatchesKeyShape() — the actual validation seam', function () {
    /** Call the protected seam directly, so this is red the moment it does not exist. */
    function matchesKeyShape(string $modelClass, string $id): bool
    {
        $controller = new ApiTokenController;
        config(['beam.accounts.tokens.model' => $modelClass]);

        $method = new ReflectionMethod($controller, 'idMatchesKeyShape');
        $method->setAccessible(true);

        return $method->invoke($controller, $id);
    }

    it('rejects a numeric id against a uuid-keyed model', function () {
        expect(matchesKeyShape(UuidPersonalAccessToken::class, '999999'))->toBeFalse();
    });

    it('accepts a well-formed uuid against a uuid-keyed model', function () {
        expect(matchesKeyShape(UuidPersonalAccessToken::class, (string) Illuminate\Support\Str::uuid()))
            ->toBeTrue();
    });

    it('rejects a non-numeric id against the int-keyed default model', function () {
        expect(matchesKeyShape(PersonalAccessToken::class, 'not-a-real-id'))->toBeFalse();
        expect(matchesKeyShape(PersonalAccessToken::class, (string) Illuminate\Support\Str::uuid()))
            ->toBeFalse();
    });

    it('accepts a numeric id against the int-keyed default model', function () {
        expect(matchesKeyShape(PersonalAccessToken::class, '42'))->toBeTrue();
    });
});
