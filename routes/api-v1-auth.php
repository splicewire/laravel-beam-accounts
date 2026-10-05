<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Data\AuthUserData;
use Splicewire\Beam\Accounts\Data\PasskeyOptionsData;
use Splicewire\Beam\Accounts\Data\PasswordResetResultData;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\LoginController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyLoginController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasswordResetController;
use Splicewire\Beam\Routing\RouteVisibility;

/*
| `Route::splicewireAccountsAuthV1()` — the GUEST half of the accounts api/v1 surface (tower-is-splicewire D5′): sign-in,
| password recovery, passkey sign-in. Inert: the host's group supplies the prefix, the name prefix and the middleware.
| The per-route throttles stay here, because each verb's brute-force ceiling is part of its contract; the macro defines
| a default for any named limiter the host has not defined itself.
*/

Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login')->name('login')
    ->beam()->visibility(RouteVisibility::Public);

// Pre-auth password recovery. Enumeration-safe (identical response) and IP-throttled independent of the login bucket.
Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])
    ->middleware('throttle:password-reset')
    ->name('password.email')->beam()->returns(PasswordResetResultData::class);
Route::post('reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password-reset')
    ->name('password.update')->beam()->returns(PasswordResetResultData::class);

Route::post('passkey/login-options', [PasskeyLoginController::class, 'options'])
    ->middleware('throttle:passkey')->name('passkey.login-options')->beam()->returns(PasskeyOptionsData::class);
Route::post('passkey/login', [PasskeyLoginController::class, 'verify'])
    ->middleware('throttle:login')->name('passkey.login')->beam()->returns(AuthUserData::class);
