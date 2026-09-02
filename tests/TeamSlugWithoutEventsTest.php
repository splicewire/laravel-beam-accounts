<?php

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Teams\TeamProvisioner;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * `beam_teams.slug` is NOT NULL, and spatie's `HasSlug` writes it from a `creating` listener — which
 * Laravel's skeleton `DatabaseSeeder` mutes for the whole seed run through `WithoutModelEvents`
 * (theme-entries-and-authoring 02; measured at `~/Herd/numero` and `laravel-beam-starter`'s
 * `migrate:fresh --seed`). A Team row has to be complete without an event firing: the slug is a
 * property of the declared shape, not of the dispatcher that happened to be present.
 */
it('provisions a personal team with a slug while model events are muted', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    $team = Model::withoutEvents(fn () => app(TeamProvisioner::class)->personalTeamFor($owner));

    expect($team->fresh()->slug)->toBe('ada');
});

it('creates a team through updateOrCreate with a slug while model events are muted', function () {
    // `DemoTeamSeeder:61` and `TeamProvisioner::personalTeamWithFullReachFor()` both insert through
    // `Team::updateOrCreate`, which fires `creating` only when the dispatcher is present.
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    $team = Model::withoutEvents(fn () => Team::updateOrCreate(
        ['user_id' => $owner->getKey(), 'personal_team' => false],
        ['name' => 'Demo Team'],
    ));

    expect($team->fresh()->slug)->toBe('demo-team');
});

it('still uniquifies a muted-event slug against an existing one', function () {
    $one = User::create(['name' => 'Ada', 'email' => 'one@site.test', 'password' => 'secret']);
    $two = User::create(['name' => 'Ada', 'email' => 'two@site.test', 'password' => 'secret']);

    Team::create(['user_id' => $one->getKey(), 'name' => 'Support', 'personal_team' => false]);

    $b = Model::withoutEvents(fn () => Team::create(['user_id' => $two->getKey(), 'name' => 'Support', 'personal_team' => false]));

    expect($b->fresh()->slug)->toBe('support-1');
});

it('leaves an explicitly supplied slug alone whether or not events fire', function () {
    $owner = User::create(['name' => 'Ada', 'email' => 'ada@site.test', 'password' => 'secret']);

    $team = Model::withoutEvents(fn () => Team::create([
        'user_id' => $owner->getKey(), 'name' => 'Support', 'personal_team' => false, 'slug' => 'chosen',
    ]));

    expect($team->fresh()->slug)->toBe('chosen');
});
