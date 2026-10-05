<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\MeController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\ProfileController;
use Splicewire\Beam\Routing\RouteVisibility;

/*
| `Route::splicewireAccountsMeV1()` — the caller's own identity on the accounts api/v1 surface (D5′). Inert: at the
| flagship it sits in the TENANT group, which resolves the tenant user; the host supplies prefix, names and guard.
*/

Route::get('me', [MeController::class, 'show'])->name('me')
    ->beam()->visibility(RouteVisibility::Public)->inResource('me')->returns(AuthUserData::class);

// Updates the caller's own name/email; roles, permissions and entitlements stay server-resolved and read-only.
Route::patch('me', [ProfileController::class, 'update'])->name('me.update')->beam()->inResource('me')->returns(AuthUserData::class);
