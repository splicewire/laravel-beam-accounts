<?php

namespace Splicewire\Beam\Accounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password-reset link notification — the email peer of the Fortify
 * `ResetUserPassword` action, engine-owned so every beam host gets a working
 * SPA reset email out of the box.
 *
 * Unlike Laravel's default (which routes at a server-rendered `password.reset`
 * route), a beam host is an SPA: the link points at the React reset route
 * (default `/ui/reset-password`), carrying the broker token + email in the query
 * string so the SPA can read them and POST to `/api/v1/reset-password`. The URL
 * base is a config seam (`beam.accounts.password_reset_url`) so a non-standard SPA
 * host/path can be pointed at without touching this class; the brand is just
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
     * The base is config-driven (`beam.accounts.password_reset_url`) so a
     * non-standard SPA host/path can be pointed at without touching this class.
     */
    public function resetUrl(CanResetPassword $notifiable): string
    {
        $base = config('beam.accounts.password_reset_url');
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return $base.'?'.$query;
    }
}
