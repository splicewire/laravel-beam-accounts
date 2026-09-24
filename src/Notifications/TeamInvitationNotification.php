<?php

namespace Splicewire\Beam\Accounts\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Splicewire\Beam\Accounts\Models\Invitation;

/**
 * The invitation email: who invited you, to which team, as what, and the signed link to
 * `invitations.accept`.
 *
 * Addressed on demand (`Notification::route('mail', …)`) because the invitee usually has no account
 * yet. Sent synchronously, like the password-reset mail beside it: the send is the response to one
 * owner's click, and a queued send would make "did it go?" a question about a worker.
 *
 * Everything that varies is resolved by the caller ({@see \Splicewire\Beam\Accounts\Teams\InvitationMailer})
 * and passed in, so the message is a pure rendering and a test can read it without a database.
 */
class TeamInvitationNotification extends Notification
{
    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $acceptUrl,
        public readonly string $teamName,
        public readonly ?string $inviterName,
        public readonly string $expiresOn,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = ucfirst((string) $this->invitation->role);
        $who = $this->inviterName ?? __('A teammate');

        return (new MailMessage)
            ->subject(__('You are invited to join :team', ['team' => $this->teamName]))
            ->line(__(':who invited you to join :team as :role.', ['who' => $who, 'team' => $this->teamName, 'role' => $role]))
            ->action(__('Accept invitation'), $this->acceptUrl)
            ->line(__('This invitation was sent to :email and expires on :date.', ['email' => $this->invitation->email, 'date' => $this->expiresOn]))
            ->line(__('If you do not have an account yet, you can create one from the link.'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation' => (string) $this->invitation->getKey(),
            'team' => $this->teamName,
            'url' => $this->acceptUrl,
        ];
    }
}
