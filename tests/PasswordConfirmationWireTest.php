<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Accounts\Data\ResetPasswordInputData;

it('validates reset password confirmation against the camel wire field', function () {
    Route::post('/__beam-accounts-reset-rule-probe', function (ResetPasswordInputData $data) {
        return response()->json(['email' => $data->email], 201);
    });

    $valid = [
        'token' => 'token',
        'email' => 'ada@example.test',
        'password' => 'valid-password-1234',
        'passwordConfirmation' => 'valid-password-1234',
    ];

    $this->postJson('/__beam-accounts-reset-rule-probe', $valid)
        ->assertCreated()
        ->assertJsonPath('email', 'ada@example.test');

    $this->postJson('/__beam-accounts-reset-rule-probe', [
        ...$valid,
        'passwordConfirmation' => 'different-password-1234',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    $this->postJson('/__beam-accounts-reset-rule-probe', [
        ...$valid,
        'password_confirmation' => $valid['password'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['password_confirmation']);
});
