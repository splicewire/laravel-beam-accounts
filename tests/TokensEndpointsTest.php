<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Enums\Role;
use Splicewire\Beam\Accounts\Models\Membership;
use Splicewire\Beam\Accounts\Models\Team;
use Splicewire\Beam\Accounts\Support\TokensEndpoints;
use Splicewire\Beam\Accounts\Tests\Fixtures\User;

/**
 * The tokens page's endpoints (frame-foundation 51): laravel-beam-accounts builds them from its own mounted route
 * names, so the page follows `beam.accounts.api_root` (`ACCOUNT_API_ROOT`) and no host spells the root. The witness is
 * a host that moved the root: the props point at the new mount, and the roster still lists through them.
 */
beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    $this->createPersonalAccessTokensSchema();
});

it('builds the tokens page endpoints from the mounted routes, so a moved api_root still lists', function () {
    config(['beam.accounts.api_root' => 'custom/root']);
    Route::splicewireAccountApiRoutes();
    Route::getRoutes()->refreshNameLookups();

    $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x']);
    $team = Team::create(['user_id' => $owner->id, 'name' => 'T', 'personal_team' => true]);
    Membership::create(['team_id' => $team->id, 'user_id' => $owner->id, 'role' => Role::Owner->value]);
    $owner->switchTeam($team);
    Auth::login($owner->fresh());

    $endpoints = TokensEndpoints::props();

    expect($endpoints)->toBe([
        'index' => '/custom/root/tokens',
        'permissions' => '/custom/root/tokens/permissions',
        'sessionsOthers' => '/custom/root/tokens/sessions/others',
    ]);

    // The page mints and lists through the same `index` URL (tokens.store / tokens.index).
    $this->postJson($endpoints['index'], ['name' => 'CI'])->assertCreated();
    $this->getJson($endpoints['index'])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'CI');
    $this->getJson('/beam/accounts/tokens')->assertNotFound();
});
