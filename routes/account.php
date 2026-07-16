<?php

use Illuminate\Support\Facades\Route;
use Schemastud\Beam\Accounts\Http\Controllers\ProfileController;
use Schemastud\Beam\Accounts\Http\Controllers\SecurityController;

/*
 * The settings surface. Fortify owns the auth routes (login/register/reset/verify);
 * this file adds the authenticated profile + security pages. Registered by the
 * `splicewireAccountRoutes` macro (see the service provider).
 */
Route::redirect('/', '/settings/profile');

Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

Route::get('security', [SecurityController::class, 'edit'])->name('security.edit');
Route::put('password', [SecurityController::class, 'update'])
    ->middleware('throttle:6,1')
    ->name('user-password.update');
