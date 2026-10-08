<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\SessionTokenController;

/*
| `Route::splicewireAccountsSessionTokenV1()` — the explicit Fortify-session → bearer bridge.
| Inert: a host mounts it in its own `web` + `auth` group. It must never share the public auth group.
*/

Route::post('session-token', SessionTokenController::class)->name('session-token.store')->beam();
