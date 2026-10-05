<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Data\PasskeyData;
use Splicewire\Beam\Accounts\Data\PasskeyOptionsData;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\LogoutController;
use Splicewire\Beam\Accounts\Http\Controllers\Api\V1\PasskeyController;

/*
| `Route::splicewireAccountsSessionV1()` — the SIGNED-IN central half of the accounts api/v1 surface (D5′): the packaged
| logout and passkey management. Inert: the host calls it inside its own authenticated group (`auth:sanctum` at the
| flagship), which supplies the prefix, the name prefix and the guard.
*/

Route::post('logout', [LogoutController::class, 'logout'])->name('logout')->beam();

Route::get('passkeys', [PasskeyController::class, 'index'])->name('passkeys.index')->beam()->returns(PasskeyData::class, many: true);
Route::post('passkeys/registration-options', [PasskeyController::class, 'registrationOptions'])
    ->middleware('throttle:passkey')->name('passkeys.registration-options')->beam()->returns(PasskeyOptionsData::class);
Route::post('passkeys', [PasskeyController::class, 'store'])->name('passkeys.store')->beam()->returns(PasskeyData::class);
Route::patch('passkeys/{passkey}', [PasskeyController::class, 'update'])->name('passkeys.update')->beam()->returns(PasskeyData::class);
Route::delete('passkeys/{passkey}', [PasskeyController::class, 'destroy'])->name('passkeys.destroy')->beam()->returns(PasskeyData::class);
