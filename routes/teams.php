<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Facades\BeamAccounts;
use Splicewire\Beam\Accounts\Http\Controllers\InvitationAcceptController;
use Splicewire\Beam\Accounts\Http\Controllers\TeamController;
use Splicewire\Beam\Accounts\Ops\CreateTeam;
use Splicewire\Beam\Accounts\Ops\RedeemInvitation;
use Splicewire\Beam\Facades\Particle;

/*
|--------------------------------------------------------------------------
| Getting onto a team — self-service creation and invitation acceptance
|--------------------------------------------------------------------------
|
| Mounted by `Route::splicewireTeamRoutes()`. The package mounts it itself when
| `beam.accounts.register_routes` is on; a host that turns that off (every beam starter does, and
| mounts its own settings routes) calls the macro from its route file.
|
|   GET  teams/create                teams.create         the form            (auth)
|   POST teams/create                teams.store          CreateTeam op       (auth)
|   GET  invitations/{token}         invitations.accept   the emailed link    (guest or auth; signed)
|   POST invitations/{id}/redeem     invitations.redeem   RedeemInvitation op (auth; `{id}` is the token)
|
| `teams.create` and `invitations.accept` are the two names `splicewire/laravel-beam-ux`'s
| `DashboardWelcome` asks the router for: a first-run user is offered "Create a team" and told about
| invitation links only where these exist.
|
| The two variables are supplied by the macro: `$authMiddleware` for the three signed-in routes and
| `$guestMiddleware` for the accept page, which a guest must be able to open.
|
| NOTHING mounts where this host has no beam team model (`BeamAccounts::teamModel()` is null: the
| teams estate is declared `'absent'`, or `beam.accounts.teams.model` names the host's own team). Every
| route here creates or seats a beam `Team` through `TeamProvisioner`, so on a host whose team is its
| tenant each would be a 500 against a `beam_teams` table that does not exist. With the names absent,
| `DashboardWelcome` offers neither "Create a team" nor the invitation hint, as it does at any host
| that never called the macro.
*/

if (BeamAccounts::teamModel() === null) {
    return;
}

Route::middleware($authMiddleware)->group(function (): void {
    Route::get('teams/create', [TeamController::class, 'create'])->name('teams.create');

    // The op mounts at `teams/create` too (NoSubject ⇒ no `{id}`), on POST.
    Particle::ops('teams', 'teams', [CreateTeam::class], ['name' => 'teams.store']);

    Particle::ops('invitations', 'invitations', [RedeemInvitation::class], ['name' => 'invitations.redeem']);
});

Route::middleware($guestMiddleware)->group(function (): void {
    Route::get('invitations/{token}', [InvitationAcceptController::class, 'show'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('invitations.accept');
});
