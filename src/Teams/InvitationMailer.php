<?php

namespace Splicewire\Beam\Accounts\Teams;

use Illuminate\Support\Facades\Notification;
use Splicewire\Beam\Accounts\Localization\PreferredLocale;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification;
use Splicewire\Beam\Localization\DeliveryRecipient;
use Splicewire\Beam\Localization\LocaleResolver;

/**
 * Put one invitation's email on the wire — the ONE call both transports make after a send or resend:
 * `InvitationData::afterWrite()` (the Frame/particle writer) and `TeamInvitationController::persist()`
 * (the account-tier REST survivor `TeamPage` talks to).
 *
 * ## When it sends nothing, and why that is not an error
 *
 *  - The host mounts no `invitations.accept` route: there is no link that could work, and a mail
 *    telling someone to "open the link" with no link is worse than none. `DashboardWelcome` reads the
 *    same route name to decide whether to mention invitations at all.
 *  - The invitation names no beam `Team` (a tower tenant's invitation), or the host has no beam team
 *    model at all ({@see \Splicewire\Beam\Accounts\Facades\BeamAccounts::teamModel()} is null): its
 *    host mails through its own `DispatchesInvitationMail`, whose link points at its own accept
 *    operation. Checked FIRST, before the route, so a host without `beam_teams` never queries it.
 *  - It is already accepted.
 *
 * Returns whether a mail was sent, so a caller (and a test) can tell a skip from a send.
 *
 * ## Its language (localization 36; ruling 03 §3)
 *
 * The invitee's, never the inviter's. A recipient who already has an account and chose a language reads it; otherwise
 * the INVITATION's workspace default (its team, the tenant on a tenancy host), then the brand's, then the app's. It is
 * sent through beam's delivery seam ({@see LocaleResolver::deliver()}): resolved once, rendered inside that locale,
 * with `->locale()` on the notification, and the expiry date formatted at render in that same language.
 */
class InvitationMailer
{
    public function __construct(private InvitationRedemption $redemption) {}

    public function send(Invitation $invitation): bool
    {
        if ($invitation->accepted_at !== null) {
            return false;
        }

        $teamName = $this->redemption->teamName($invitation);
        $url = $this->redemption->acceptUrl($invitation);

        if ($teamName === null || $url === null) {
            return false;
        }

        $route = Notification::route('mail', $invitation->email);
        $recipient = new DeliveryRecipient(
            PreferredLocale::centralByEmail($invitation->email) ?? $route,
            workspace: $this->redemption->team($invitation),
        );
        $expiresAt = $this->redemption->expiresAt($invitation);

        app(LocaleResolver::class)->deliver($recipient, fn (string $locale) => $route->notify((new TeamInvitationNotification(
            invitation: $invitation,
            acceptUrl: $url,
            teamName: $teamName,
            inviterName: $this->redemption->inviterName($invitation),
            expiresOn: $expiresAt->toFormattedDayDateString(),
        ))->expiringAt($expiresAt)->locale($locale)));

        return true;
    }
}
