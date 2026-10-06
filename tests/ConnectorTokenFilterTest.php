<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\Data\TokenData;

/**
 * UX-10 (IA-7): `connector:<label>` tokens belong to the Connected sites hub, so they are filtered OUT of
 * the plain token list — host-safe: every other token still lists exactly as before. A pass-through scope
 * seam isolates the connector-prefix exclusion (TokenData::scope) from the ownership boundary
 * (TokensQuery::scopeToOwner), which has its own tests.
 */
class ConnectorFilterToken extends Model
{
    protected $table = 'personal_access_tokens';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('personal_access_tokens', function (Blueprint $table): void {
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
    // Isolate the connector:* filter from scopeToOwner's morph boundary (which has its own tests).
    config(['beam.accounts.tokens.scope' => fn (Builder $q, $u) => $q]);
});

afterEach(fn () => Schema::dropIfExists('personal_access_tokens'));

function mkToken(string $name): void
{
    ConnectorFilterToken::create([
        'id' => (string) Str::uuid(),
        'tokenable_type' => 'user', 'tokenable_id' => '1',
        'name' => $name, 'token' => hash('sha256', $name),
    ]);
}

it('hides connector:* tokens from the plain list but keeps every other token (host-safe)', function () {
    mkToken('connector:satellite.test');
    mkToken('my-laptop');
    mkToken('zapier');

    $names = TokenData::scope(ConnectorFilterToken::query())->pluck('name')->all();

    expect($names)->toContain('my-laptop')->toContain('zapier')   // non-connector tokens unchanged
        ->not->toContain('connector:satellite.test');            // connector token hidden
});

it('matches only the connector: PREFIX — a name that merely contains "connector" still lists', function () {
    mkToken('my-connector-notes');  // not a connector:<label> token
    mkToken('connector:x');

    $names = TokenData::scope(ConnectorFilterToken::query())->pluck('name')->all();

    expect($names)->toContain('my-connector-notes')->not->toContain('connector:x');
});
