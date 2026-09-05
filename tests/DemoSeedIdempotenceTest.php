<?php

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Accounts\Database\Seeders\DemoTeamSeeder;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Facades\BeamDemo;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/*
 * The seeder's own docblock claims it is idempotent, and every row it writes goes through
 * `firstOrCreate` / `updateOrCreate` — except one. `TeamProvisioner::personalTeamFor()` was a
 * plain `Team::create()`, so the `solo` subject minted a NEW personal team on every re-seed,
 * with a fresh owner membership and a fresh team-scoped role row pointing at it, and the
 * user's `current_team_id` silently repointed to the newest.
 *
 * A SINGLE run passes against that defect. Every case below runs the seeding twice on purpose;
 * that is the whole instrument. Reverting `personalTeamFor()` to `Team::create()` turns
 * "keeps ONE personal team for the solo subject across re-seeds" red (2 teams, not 1).
 */

/** The `solo` subject models the default team-of-one; it is the one row that leaked. */
function soloUser(): User
{
    return User::query()->where('email', BeamDemo::email('solo'))->firstOrFail();
}

function personalTeamsFor(User $user): int
{
    return Team::query()
        ->where('user_id', $user->getKey())
        ->where('personal_team', true)
        ->count();
}

it('keeps ONE personal team for the solo subject across re-seeds', function () {
    app(DemoTeamSeeder::class)->run();
    $first = soloUser()->personalTeam();

    app(DemoTeamSeeder::class)->run();

    // The COUNT is the load-bearing assertion. `personalTeam()` is an unordered `->first()`, so
    // under the defect it can hand back the same row twice — it discriminates nothing on its own.
    expect(personalTeamsFor(soloUser()))->toBe(1);
    expect(soloUser()->personalTeam()->getKey())->toBe($first->getKey());
});

it('does not repoint the solo subject at a newer team on a re-seed', function () {
    app(DemoTeamSeeder::class)->run();
    $before = soloUser()->current_team_id;

    app(DemoTeamSeeder::class)->run();

    expect(soloUser()->current_team_id)->toBe($before);
});

it('leaves no orphaned membership or role row behind on a re-seed', function () {
    app(DemoTeamSeeder::class)->run();
    app(DemoTeamSeeder::class)->run();

    $solo = soloUser();
    $team = $solo->personalTeam();

    expect(Membership::query()->where('user_id', $solo->getKey())->count())->toBe(1);
    expect(Membership::query()->where('user_id', $solo->getKey())->value('team_id'))
        ->toBe($team->getKey());

    // Every `model_has_roles` row for this user must point at a team that still exists —
    // an orphan is a row scoped to a team nothing else references any more.
    $scopedTeamIds = DB::table('model_has_roles')
        ->where('model_id', $solo->getKey())
        ->pluck(config('permission.column_names.team_foreign_key', 'team_id'))
        // String-compare: the query builder returns the raw column, the model returns its cast
        // key, and the estate's own rule is that a key comparison is string-vs-string — an `(int)`
        // cast here would be the uuid trap in miniature.
        ->map(fn ($id) => (string) $id)
        ->unique()
        ->values()
        ->all();

    expect($scopedTeamIds)->toBe([(string) $team->getKey()]);
});

it('mints one team when the provisioner is called twice directly', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => 'password-1234']);

    $first = app(TeamProvisioner::class)->personalTeamFor($user);
    $second = app(TeamProvisioner::class)->personalTeamFor($user);

    expect($second->getKey())->toBe($first->getKey());
    expect(personalTeamsFor($user->fresh()))->toBe(1);
    expect(Membership::query()->where('user_id', $user->getKey())->where('role', Role::Owner->value)->count())->toBe(1);
});
