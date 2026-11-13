<?php

namespace Splicewire\Beam\Accounts\Http\Controllers\Api\V1;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Splicewire\Beam\Accounts\Data\ForgotPasswordInputData;
use Splicewire\Beam\Accounts\Data\PasswordResetResultData;
use Splicewire\Beam\Accounts\Data\ResetPasswordInputData;
use Splicewire\Beam\Accounts\Localization\AccountError;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Controller;

/**
 * Pre-auth password recovery for the first-party SPA, relocated down from Tower (HTTP-07) on the
 * canonical Data shape. Mints no token (recovery, not a session grant), so the projection question does
 * not arise; the two write bodies convert to `{Concept}InputData`.
 *
 * Two endpoints backed by Laravel's password broker + `password_reset_tokens` table. Deliberately
 * excluded from the generated API reference — this is SPA session recovery, not a machine-client surface.
 */
class PasswordResetController extends Controller
{
    /**
     * Request a reset link.
     *
     * ALWAYS returns an identical success response whether or not the email belongs to an account (no
     * user enumeration). The broker only dispatches the notification when a user actually exists; the
     * response never reveals which case occurred. Brute-force is bounded by the `password-reset` throttle
     * on the route.
     */
    public function sendResetLink(ForgotPasswordInputData $input): ResponseBody
    {
        // Discard the broker status on purpose: RESET_LINK_SENT, INVALID_USER, and RESET_THROTTLED must
        // all look identical from the outside.
        Password::broker()->sendResetLink(['email' => $input->email]);

        // The data slot carries a machine-readable outcome (never the submitted email — the
        // response must stay byte-identical across existing/missing accounts).
        return ResponseBody::from([
            'message' => __('beam-accounts::messages.reset_link_sent'),
            'data' => new PasswordResetResultData(status: 'link-sent'),
        ]);
    }

    /**
     * Confirm a reset with token + new password.
     *
     * A valid token sets the new password; an expired or tampered token is rejected with a 422
     * validation error.
     */
    public function reset(ResetPasswordInputData $input): ResponseBody
    {
        $status = Password::broker()->reset(
            [
                'email' => $input->email,
                'password' => $input->password,
                'password_confirmation' => $input->passwordConfirmation,
                'token' => $input->token,
            ],
            function (CanResetPassword $user, string $password) {
                // The User model casts `password` => 'hashed', so assigning the plaintext hashes it once
                // on save (no double-hash).
                $user->forceFill(['password' => $password])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $error = match ($status) {
                Password::INVALID_USER => AccountError::ResetInvalidUser,
                Password::RESET_THROTTLED => AccountError::ResetThrottled,
                default => AccountError::ResetInvalidToken,
            };
            throw \Splicewire\Beam\Localization\CodedValidationException::for('email', $error);
        }

        return ResponseBody::from([
            'message' => __('beam-accounts::messages.password_reset'),
            'data' => new PasswordResetResultData(status: 'reset'),
        ]);
    }
}
