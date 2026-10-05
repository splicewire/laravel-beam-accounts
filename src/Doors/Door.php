<?php

namespace Splicewire\Beam\Accounts\Doors;

/** The ways a central user may come into existence (purchase-walkthrough M10). Each is admitted by {@see AccountDoors}. */
enum Door: string
{
    /** Public self-registration (Fortify). Open only when `beam.accounts.doors.registration` is `open`. */
    case Register = 'register';

    /** Sign-up through an OAuth provider: by exact email domain, never by default. */
    case OAuth = 'oauth';

    /** Claiming an invitation: email-bound, and always available wherever invitations exist. */
    case Invite = 'invite';

    /** An operator creating a user. Open while `beam.accounts.doors.operator` is true. */
    case Operator = 'operator';

    /** A machine/service user a package provisions for itself. Always admitted; never a person. */
    case Service = 'service';
}
