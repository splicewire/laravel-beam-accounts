<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Accounts\BeamAccountsServiceProvider;

/**
 * `guest_tokens.landing_url` is nullable in the create stub and was `NOT NULL` in every live schema.
 *
 * The gap is the one `AGENTS.md` names outright: editing a `create_*` stub changes only what a FRESH
 * database gets, and {@see \Rushing\SchemaConvergence\ConvergentTable::assert()} adds missing columns
 * but never alters an existing column's nullability — so neither instrument could reach the 17 of 17
 * flagship tenant schemas measured 2026-08-29 as `is_nullable = 'NO'` against a published copy that
 * was already byte-identical to the nullable stub.
 *
 * ⚠️ The happy path is NOT the test here. A schema created from the current stub is *already*
 * nullable, so a test that creates the table from the stub and inserts a null would pass against the
 * defect — the shape this estate calls a gate-open trace. The first case below therefore builds the
 * PRE-FIX shape by hand (`$table->string('landing_url')` — no `nullable()`), proves the insert fails
 * there, and only then runs the ALTER. Against a tree without the ALTER stub, the `require` fatals.
 */
function relaxLandingUrlMigration(): object
{
    return require __DIR__.'/../database/migrations/tenant/relax_guest_token_landing_url_nullability.php.stub';
}

/**
 * The shape a tenant schema migrated before the stub was made nullable actually holds.
 */
function createPreFixGuestTokensTable(): void
{
    Schema::dropIfExists('guest_tokens');

    Schema::create('guest_tokens', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('token', 64)->unique();
        $table->string('scope');
        $table->string('role');
        $table->string('landing_url'); // NOT NULL — the defect under repair
        $table->unsignedInteger('use_count')->default(0);
    });
}

function insertGuestTokenWithoutLandingUrl(string $token): void
{
    DB::table('guest_tokens')->insert([
        'id' => (string) Str::uuid(),
        'token' => $token,
        'scope' => 'entity',
        'role' => 'guest',
        'landing_url' => null,
    ]);
}

afterEach(fn () => Schema::dropIfExists('guest_tokens'));

it('drops NOT NULL on a schema that was migrated before the create stub became nullable', function () {
    createPreFixGuestTokensTable();

    // The defect, stated as a failing write rather than as a catalog read: an entity-intake link
    // cannot be minted at all, because its landing position is computed at RESOLVE time and there is
    // nothing to freeze at MINT time.
    expect(fn () => insertGuestTokenWithoutLandingUrl('before'))
        ->toThrow(Illuminate\Database\QueryException::class);

    relaxLandingUrlMigration()->up();

    insertGuestTokenWithoutLandingUrl('after');

    expect(DB::table('guest_tokens')->where('token', 'after')->value('landing_url'))->toBeNull();
});

it('is a true no-op on a schema that is already nullable, and does not redefine the column', function () {
    Schema::dropIfExists('guest_tokens');

    Schema::create('guest_tokens', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('token', 64)->unique();
        $table->string('scope');
        $table->string('role');
        $table->string('landing_url')->nullable();
        // A column the host lengthened for its own reasons. `->change()` is a full column
        // redefinition, so an unguarded ALTER over an already-correct schema would be free to
        // restate — and reset — declarations this migration never knew about. The guard is what
        // makes "already nullable" mean "not touched" rather than "touched harmlessly".
        $table->string('scope_hint', 512)->nullable();
        $table->unsignedInteger('use_count')->default(0);
    });

    relaxLandingUrlMigration()->up();

    insertGuestTokenWithoutLandingUrl('noop');

    expect(DB::table('guest_tokens')->where('token', 'noop')->value('landing_url'))->toBeNull()
        ->and(Schema::hasColumn('guest_tokens', 'scope_hint'))->toBeTrue();
});

it('does not fatal on a host that has no guest_tokens table at all', function () {
    // `publish_auth_migrations` off, or a tenant estate that predates the table. A migration that
    // throws here is a check whose answer depends on the host.
    Schema::dropIfExists('guest_tokens');

    relaxLandingUrlMigration()->up();

    expect(Schema::hasTable('guest_tokens'))->toBeFalse();
});

it('is declared in the auth publish estate, immediately after its own create', function () {
    $auth = BeamAccountsServiceProvider::gatedEstates()['auth_migrations'];

    $create = array_search('tenant/create_guest_tokens_table', $auth, true);
    $alter = array_search('tenant/relax_guest_token_landing_url_nullability', $auth, true);

    // package-tools stamps published copies a second apart in LISTED order, so this ordering is the
    // only thing guaranteeing the ALTER sorts after the create on a fresh publish.
    expect($create)->not->toBeFalse()
        ->and($alter)->not->toBeFalse()
        ->and($alter)->toBe($create + 1);
});
