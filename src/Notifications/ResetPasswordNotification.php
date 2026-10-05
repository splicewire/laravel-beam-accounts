<?php

namespace Splicewire\Beam\Accounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Password-reset link notification — the email peer of the Fortify
 * `ResetUserPassword` action, engine-owned so every beam host gets a working
 * SPA reset email out of the box.
 *
 * The link points where THIS host resets passwords: the page a host declares in
 * `beam.accounts.password_reset_url` (an SPA such as the flagship's `/ui/reset-password`,
 * which reads the token + email from the query and POSTs to `/api/v1/reset-password`),
 * else the named `password.reset` page (Fortify's, at the Inertia starters), else
 * `/reset-password`. The package ships no app path. The brand is just
 * `app.name` — the universal, tenant-overridable carrier (a tenant maps it through
 * the tenancy config seam), never an accounts-scoped key.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $brand = config('app.name');

        return (new MailMessage)
            ->subject("Reset your {$brand} password")
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line("This password reset link will expire in {$minutes} minutes.")
            ->line('If you did not request a password reset, no further action is required.');
    }

    /**
     * The SPA reset URL carrying the broker token + email.
     *
     * The host-declared page (`beam.accounts.password_reset_url`), else the named
     * `password.reset` route, else `/reset-password`.
     */
    public function resetUrl(CanResetPassword $notifiable): string
    {
        $email = $notifiable->getEmailForPasswordReset();
        $base = config('beam.accounts.password_reset_url');

        if (is_string($base) && trim($base) !== '') {
            return $base.'?'.http_build_query(['token' => $this->token, 'email' => $email]);
        }

        if (Route::has('password.reset')) {
            return route('password.reset', ['token' => $this->token]).'?'.http_build_query(['email' => $email]);
        }

        return url('/reset-password').'?'.http_build_query(['token' => $this->token, 'email' => $email]);
    }
}
