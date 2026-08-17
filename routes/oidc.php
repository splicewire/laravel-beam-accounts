<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Http\Controllers\OidcDiscoveryController;

Route::get('/.well-known/openid-configuration', [OidcDiscoveryController::class, 'discovery'])->name('beam.oidc.discovery');
Route::get('/.well-known/jwks.json', [OidcDiscoveryController::class, 'jwks'])->name('beam.oidc.jwks');
