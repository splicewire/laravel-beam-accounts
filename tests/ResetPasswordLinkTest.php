<?php

/*
 * The reset-link email points where THIS host resets passwords, not at a packaged `/ui` literal (tower-is-splicewire prep
 * slice 04). A host that resets in an SPA declares `beam.accounts.password_reset_url`; otherwise the link is the named
 * `password.reset` page (Fortify's), else `/reset-password` on the app URL.
 */

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Notifications\ResetPasswordNotification;

function resettable(): Illuminate\Contracts\Auth\CanResetPassword
{
    return new class implements Illuminate\Contracts\Auth\CanResetPassword
    {
        public function getEmailForPasswordReset(): string
        {
            return 'ada@example.test';
        }

        public function sendPasswordResetNotification($token): void {}
    };
}

it('ships no /ui literal as its default', function () {
    $shipped = require __DIR__.'/../config/beam/accounts.php';

    expect($shipped['password_reset_url'])->toBeNull();
});

it('uses the host-declared reset URL, carrying the token and email', function () {
    config(['beam.accounts.password_reset_url' => 'https://app.example.test/ui/reset-password']);

    expect((new ResetPasswordNotification('tok'))->resetUrl(resettable()))
        ->toBe('https://app.example.test/ui/reset-password?token=tok&email=ada%40example.test');
});

it('falls back to the named password.reset page when the host declares none', function () {
    config(['beam.accounts.password_reset_url' => null]);
    Route::get('reset-password/{token}', fn () => 'ok')->name('password.reset');
    app('router')->getRoutes()->refreshNameLookups();

    expect((new ResetPasswordNotification('tok'))->resetUrl(resettable()))
        ->toBe(url('/reset-password/tok').'?email=ada%40example.test');
});
