<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Http\Controllers\Account\ApiTokenController;
use Splicewire\Beam\Accounts\Http\Controllers\Account\TeamInvitationController;
use Splicewire\Beam\Accounts\Http\Controllers\Account\TeamMemberController;

/*
|--------------------------------------------------------------------------
| The account-tier REST survivors — tokens, members, invitations
|--------------------------------------------------------------------------
|
| Mounted by `Route::splicewireAccountApiRoutes()`, which a HOST calls from its own route file. It is
| deliberately NOT auto-registered the way `splicewireAccountRoutes()` is, for two reasons that both
| bite in production:
|
|  1. `~/Herd/splicewire-app` already mounts this exact family — same URIs, same route NAMES — from
|     `routes/tenant.php` against tower's cross-guard controllers. Two registrations of one name is a
|     silent overwrite in `RouteCollection::addLookups()`; Laravel only refuses the pair at
|     `route:cache`. A package that registered these unconditionally would decide, by provider order,
|     which `beam.accounts.tokens.index` the flagship serves.
|  2. api-surface-coherence 141 ruled that middleware is part of an EXPOSURE and "the route file owns
|     it". These verbs mint bearer credentials and change who can reach a team; which guard, which
|     throttle and which prefix they sit behind is a host fact.
|
| The URIs match the flagship's existing spelling EXACTLY, because
| `@splicewire/beam-accounts`' `TokensClient` / `TeamClient` are written against them and must keep
| working at either host: `{api_root}/…`, the standard `{data: …}` envelope on every verb, and the
| snake `expires_in_days` body key. Until ticket 16 the route NAMES carry one of two stems:
| `beam.accounts.api.{resource}.{verb}` when `AccountsRoutes::mount()` mounts this file, and the legacy
| `beam.accounts.{resource}.{verb}` when the `splicewireAccountApiRoutes()` macro does (bare or prefixed).
|
| Verb/sub-resource routes precede `{id}` so a literal segment is never read as an id.
*/

Route::prefix('tokens')->name('tokens.')->group(function () {
    // The scoped-create picker's vocabulary. A literal segment, so it precedes `{id}` — and it is a
    // GET on the collection, so it precedes nothing else.
    Route::get('permissions', [ApiTokenController::class, 'permissions'])->name('permissions');

    // The collection index stamps the `tokens` resource and mounts its hook-event catalog beside it, so the mount
    // gets the same `{root}/tokens/discovery` and `{root}/tokens/hooks/events` the flagship's legacy tenant mount
    // has (owner-review-20261112 56).
    Route::get('/', [ApiTokenController::class, 'index'])->name('index')
        ->beam()->inResource('tokens', hookEvents: true);
    Route::post('/', [ApiTokenController::class, 'store'])->name('store');

    // Renew (extend expiry, same secret) + rotate (fresh secret, archive the old one).
    Route::post('{id}/renew', [ApiTokenController::class, 'renew'])->name('renew');
    Route::post('{id}/rotate', [ApiTokenController::class, 'rotate'])->name('rotate');

    // "Log out everywhere else" — MUST precede `{id}` so `sessions` is not read as an id.
    Route::delete('sessions/others', [ApiTokenController::class, 'destroyOtherSessions'])->name('sessions.others');

    // Permanent hard-delete (archived tokens only) — MUST precede `{id}`.
    Route::delete('{id}/permanent', [ApiTokenController::class, 'destroy'])->name('destroy');

    // Archive (soft-revoke + retain for audit) — the default kill for a live token.
    Route::delete('{id}', [ApiTokenController::class, 'archive'])->name('archive');
});

Route::prefix('members')->name('members.')->group(function () {
    // `roles` precedes the collection + `{id}` routes (literal segment).
    Route::get('roles', [TeamMemberController::class, 'roles'])->name('roles');
    Route::get('/', [TeamMemberController::class, 'index'])->name('index');
    Route::put('{id}/role', [TeamMemberController::class, 'updateRole'])->name('update-role');
    Route::delete('{id}', [TeamMemberController::class, 'remove'])->name('remove');
});

Route::prefix('invitations')->name('invitations.')->group(function () {
    // Stamps the `invitations` resource, which mounts `{root}/invitations/discovery` (owner-review-20261112 56).
    Route::get('/', [TeamInvitationController::class, 'index'])->name('index')->beam()->inResource('invitations');
    Route::post('/', [TeamInvitationController::class, 'send'])->name('send');
    Route::post('{id}/resend', [TeamInvitationController::class, 'resend'])->name('resend');
    Route::delete('{id}', [TeamInvitationController::class, 'revoke'])->name('revoke');
});
