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
        $knownRole = \Splicewire\Beam\Accounts\Enums\Role::tryFrom((string) $this->invitation->role);
        if ($knownRole !== null) {
            $role = app(\Splicewire\Beam\Localization\BeamSchemaText::class)->enumCaseLabel($knownRole, $role);
        }
        $who = $this->inviterName ?? __('beam-accounts::messages.inviter');

        return (new MailMessage)
            ->subject(__('beam-accounts::messages.invitation_subject', ['team' => $this->teamName]))
            ->line(__('beam-accounts::messages.invitation_intro', ['who' => $who, 'team' => $this->teamName, 'role' => $role]))
            ->action(__('beam-accounts::messages.invitation_action'), $this->acceptUrl)
            ->line(__('beam-accounts::messages.invitation_expiry', ['email' => $this->invitation->email, 'date' => $this->expiresOn]))
            ->line(__('beam-accounts::messages.invitation_register'));
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
