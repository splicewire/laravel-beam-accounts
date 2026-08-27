<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;
use Splicewire\Beam\Facades\Beam;

/**
 * `beam_teams.slug` — the model's generation rule, and the three-step ALTER that gets a POPULATED host
 * to the same place (beam-facade 159, D5).
 *
 * The migration half is exercised against the SHIPPED STUB rather than a restatement of it, because
 * this estate's recurring defect is a check that passes by not running the thing it claims to check.
 * The stub is loaded and run over a table deliberately regressed to the pre-slug shape.
 */
function runSlugStub(): void
{
    $migration = require __DIR__.'/../database/migrations/shared/add_slug_to_teams_table.php.stub';

    $migration->up();
}

/**
 * Put `beam_teams` back into the shape a host had before this ticket: no slug column at all.
 *
 * The unique index goes first — sqlite refuses to drop a column an index still names, which is the
 * same asymmetry the migration relies on in the other direction (backfill, then index, then NOT NULL).
 */
function dropSlugColumn(): void
{
    Schema::table(Beam::table('teams'), function (Blueprint $table): void {
        $table->dropUnique(['slug']);
    });

    Schema::table(Beam::table('teams'), function (Blueprint $table): void {
        $table->dropColumn('slug');
    });
}

it('generates a slug from the team name', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    $team = Team::create(['user_id' => $owner->getKey(), 'name' => 'Acme Support', 'personal_team' => false]);

    expect($team->slug)->toBe('acme-support');
});

it('derives a personal team slug from the OWNER, not from the name', function () {
    // `personalTeamName()` renders "{name}'s Team" for everybody, so a name-derived slug collides for
    // every user sharing a first name and degrades into `adas-team-7`.
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    $team = app(TeamProvisioner::class)->personalTeamFor($owner);

    expect($team->slug)->toBe('ada');
});

it('keeps slugs globally unique across owners', function () {
    $one = User::create(['name' => 'Ada', 'email' => 'one@site.test', 'password' => 'secret']);
    $two = User::create(['name' => 'Ada', 'email' => 'two@site.test', 'password' => 'secret']);

    $a = Team::create(['user_id' => $one->getKey(), 'name' => 'Support', 'personal_team' => false]);
    $b = Team::create(['user_id' => $two->getKey(), 'name' => 'Support', 'personal_team' => false]);

    expect($a->slug)->toBe('support')->and($b->slug)->toBe('support-1');
});

it('does NOT re-slug on rename — the slug is an address, not a display name', function () {
    // A `to_teams:` selector in a schema names this string. Renaming a team must not silently
    // repoint every notification aimed at it.
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    $team = Team::create(['user_id' => $owner->getKey(), 'name' => 'Support', 'personal_team' => false]);
    $team->update(['name' => 'Customer Success']);

    expect($team->fresh()->slug)->toBe('support');
});

it('adds, backfills and constrains the column on a populated table — the shipped stub, not a restatement', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    Team::create(['user_id' => $owner->getKey(), 'name' => 'Acme', 'personal_team' => false]);
    Team::create(['user_id' => $owner->getKey(), 'name' => 'Ada\'s Team', 'personal_team' => true]);

    dropSlugColumn();
    expect(Schema::hasColumn(Beam::table('teams'), 'slug'))->toBeFalse();

    runSlugStub();

    $slugs = DB::table(Beam::table('teams'))->orderBy('id')->pluck('slug')->all();

    expect($slugs)->toBe(['acme', 'ada'])
        ->and(Schema::hasColumn(Beam::table('teams'), 'slug'))->toBeTrue();
});

it('uniquifies a backfill over the rows that make this hazardous — same-named teams', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    foreach (range(1, 3) as $i) {
        Team::create(['user_id' => $owner->getKey(), 'name' => 'Support', 'personal_team' => false]);
    }

    dropSlugColumn();
    runSlugStub();

    expect(DB::table(Beam::table('teams'))->orderBy('id')->pluck('slug')->all())
        ->toBe(['support', 'support-1', 'support-2']);
});

it('is idempotent — a second run over a fully-slugged table changes nothing', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    Team::create(['user_id' => $owner->getKey(), 'name' => 'Acme', 'personal_team' => false]);

    dropSlugColumn();
    runSlugStub();
    $first = DB::table(Beam::table('teams'))->pluck('slug')->all();

    runSlugStub();

    expect(DB::table(Beam::table('teams'))->pluck('slug')->all())->toBe($first);
});

it('leaves the column NOT NULL and uniquely indexed once it has run', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    Team::create(['user_id' => $owner->getKey(), 'name' => 'Acme', 'personal_team' => false]);

    dropSlugColumn();
    runSlugStub();

    $unique = collect(Schema::getIndexes(Beam::table('teams')))
        ->contains(fn (array $i) => ($i['unique'] ?? false) && ($i['columns'] ?? []) === ['slug']);

    $nullable = collect(Schema::getColumns(Beam::table('teams')))
        ->firstWhere('name', 'slug')['nullable'] ?? null;

    expect($unique)->toBeTrue()->and($nullable)->toBeFalse();
});
