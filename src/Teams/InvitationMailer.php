<?php

namespace Splicewire\Beam\Accounts\Teams;

use Illuminate\Support\Facades\Notification;
use Splicewire\Beam\Accounts\Models\Invitation;
use Splicewire\Beam\Accounts\Notifications\TeamInvitationNotification;

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
 *  - The invitation names no beam `Team` (a tower tenant's invitation): its host mails through its own
 *    `DispatchesInvitationMail`, whose link points at its own accept operation.
 *  - It is already accepted.
 *
 * Returns whether a mail was sent, so a caller (and a test) can tell a skip from a send.
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

        Notification::route('mail', $invitation->email)->notify(new TeamInvitationNotification(
            invitation: $invitation,
            acceptUrl: $url,
            teamName: $teamName,
            inviterName: $this->redemption->inviterName($invitation),
            expiresOn: $this->redemption->expiresAt($invitation)->toFormattedDayDateString(),
        ));

        return true;
    }
}
