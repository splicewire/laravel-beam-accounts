<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;
use Splicewire\Beam\Accounts\Data\ApiTokenData;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Enums\TokenProvenance;
use Splicewire\Beam\Accounts\Keys\TokenLastFour;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\PersonalAccessToken;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * A token's last four characters, captured at mint (owner-review-20261102 K-28).
 *
 * The plaintext exists only in the mint response; the row keeps a SHA-256 of it. So the last four
 * can only be written by the code that has the plaintext in hand, and a row minted before the column
 * existed can never get one. Those rows answer `lastFour: null`, and the roster shows the tail of the
 * token id instead: the id is the `{id}|` half of the string the holder has, not secret material.
 *
 * The column arrives through a new publish-only ALTER stub, so at every host it is absent until
 * that host publishes and migrates. A mint must keep working there, so the last cases build the
 * pre-K-28 table and mint against it.
 */
function lastFourMigration(): object
{
    return require __DIR__.'/../database/migrations/add_last_four_to_personal_access_tokens_table.php.stub';
}

/** The `personal_access_tokens` shape every host holds today: the create plus the provenance ALTER, no `last_four`. */
function createPreLastFourTokensTable(): void
{
    Schema::dropIfExists('personal_access_tokens');

    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->id();
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
    });
}

/** A signed-in team owner, the principal the account token routes mint for. */
function lastFourOwner(): User
{
    $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());

    return $owner->fresh();
}

/** One listed row by id, off the account list route. */
function listedToken($test, string $id): array
{
    return collect($test->getJson('/beam/accounts/tokens')->assertOk()->json('data'))
        ->firstWhere('id', $id);
}

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->createPersonalAccessTokensSchema();
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();
});

// ── The column ──────────────────────────────────────────────────────────────────────────────

it('adds a nullable last_four to an existing token table and backfills nothing', function () {
    createPreLastFourTokensTable();
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => 'user', 'tokenable_id' => (string) Str::uuid(), 'name' => 'legacy',
        'token' => hash('sha256', 'legacy'), 'abilities' => '["*"]',
    ]);

    lastFourMigration()->up();

    $column = collect(Schema::getColumns('personal_access_tokens'))->firstWhere('name', 'last_four');

    // The plaintext of a row minted before this column is gone, so there is nothing to backfill from.
    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('name', 'legacy')->value('last_four'))->toBeNull();

    lastFourMigration()->down();

    expect(Schema::hasColumn('personal_access_tokens', 'last_four'))->toBeFalse();
});

it('does not fatal on a host that has no token table at all', function () {
    Schema::dropIfExists('personal_access_tokens');

    lastFourMigration()->up();
    lastFourMigration()->down();

    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse();
});

it('is declared in the auth publish estate, immediately after the provenance ALTER', function () {
    $auth = BeamAccountsServiceProvider::gatedEstates()['auth_migrations'];

    $provenance = array_search('add_provenance_and_archived_to_personal_access_tokens_table', $auth, true);
    $lastFour = array_search('add_last_four_to_personal_access_tokens_table', $auth, true);

    // package-tools stamps published copies a second apart in LISTED order, so this position is what
    // makes the ALTER sort after the create it alters on a fresh publish.
    expect($provenance)->not->toBeFalse()
        ->and($lastFour)->toBe($provenance + 1);
});

// ── Capture at mint ─────────────────────────────────────────────────────────────────────────

it('stores the last four of the minted plaintext and lists it', function () {
    lastFourOwner();

    $created = $this->postJson('/beam/accounts/tokens', ['name' => 'ci'])->assertCreated()->json('data');
    $expected = substr($created['token'], -4);

    expect(DB::table('personal_access_tokens')->where('id', $created['id'])->value('last_four'))->toBe($expected)
        ->and(listedToken($this, (string) $created['id'])['lastFour'])->toBe($expected);
});

it('stores the replacement secret\'s last four on rotate', function () {
    lastFourOwner();
    $original = $this->postJson('/beam/accounts/tokens', ['name' => 'deploy'])->json('data');

    $rotated = $this->postJson("/beam/accounts/tokens/{$original['id']}/rotate", [])->assertCreated()->json('data');

    expect(listedToken($this, (string) $rotated['id'])['lastFour'])->toBe(substr($rotated['token'], -4))
        // The archived original keeps the four it was minted with: it still names the secret the
        // holder had, which is the one a person compares against.
        ->and(listedToken($this, (string) $original['id'])['lastFour'])->toBe(substr($original['token'], -4));
});

it('lists a token minted before the column with lastFour null, not an error', function () {
    $owner = lastFourOwner();
    $legacy = PersonalAccessToken::forceCreate([
        'tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->getKey(),
        'name' => 'satellite', 'token' => hash('sha256', 'legacy-secret'), 'abilities' => ['*'],
    ]);

    expect(listedToken($this, (string) $legacy->getKey()))
        ->toHaveKey('lastFour')
        ->and(listedToken($this, (string) $legacy->getKey())['lastFour'])->toBeNull();
});

// ── A host that has not migrated yet ────────────────────────────────────────────────────────

it('still mints and lists on a host whose table has no last_four column yet', function () {
    createPreLastFourTokensTable();
    lastFourOwner();

    $created = $this->postJson('/beam/accounts/tokens', ['name' => 'before-migrate'])->assertCreated()->json('data');

    expect(PersonalAccessToken::findToken($created['token']))->not->toBeNull()
        ->and(listedToken($this, (string) $created['id'])['lastFour'])->toBeNull();
});

it('reads last four off a row loaded without the column, even under strict attribute access', function () {
    $owner = lastFourOwner();
    $this->postJson('/beam/accounts/tokens', ['name' => 'strict'])->assertCreated();

    PersonalAccessToken::preventAccessingMissingAttributes();

    try {
        $partial = PersonalAccessToken::query()->select('id', 'name')->where('name', 'strict')->sole();

        expect(TokenLastFour::read($partial))->toBeNull()
            ->and(TokenLastFour::read(PersonalAccessToken::where('name', 'strict')->sole()))->toHaveLength(4);
    } finally {
        PersonalAccessToken::preventAccessingMissingAttributes(false);
    }
});

it('takes the same four from a bare secret and from Sanctum\'s id|secret bearer', function () {
    expect(TokenLastFour::of('abcd1234wxyz'))->toBe('wxyz')
        ->and(TokenLastFour::of('9b2e-uuid|abcd1234wxyz'))->toBe('wxyz');
});

it('still builds the list row for a host controller that does not pass lastFour yet', function () {
    // tower's own controller builds this DTO and its live checkout follows this package through a
    // path link, so this package can move first. The row must serialize, with the null the roster
    // renders as the id tail, rather than fail construction.
    $row = new ApiTokenData(
        id: '01a1ac1b-5ff1-7000-8000-000000006fa8',
        name: 'satellite',
        provenance: TokenProvenance::Api,
        abilities: null,
        createdAt: null,
        lastUsedAt: null,
        expiresAt: null,
        archivedAt: null,
        isCurrent: false,
    );

    expect($row->toArray())->toHaveKey('lastFour')
        ->and($row->toArray()['lastFour'])->toBeNull();
});
