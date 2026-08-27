<?php

namespace Splicewire\Beam\Accounts\Notify;

use Splicewire\Beam\Notifications\Contracts\AccountsDirectory;
use Splicewire\Beam\Notifications\Contracts\RecipientKind;
use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * The `to_roles:` recipient kind — every member holding one of the named membership roles, as a
 * persistent Notifiable, so the whole channel set including the `database` inbox is reachable.
 *
 * Registered into `beam.notifications.recipient_kinds` by this package's provider. beam-notifications
 * knows nothing about it: an unregistered kind is an absent config entry, so a host without this
 * package simply has no `to_roles` key and hears about it the first time a schema uses one.
 *
 * `owner|admin|member` on day one — see {@see MembershipDirectory} for why the vocabulary is
 * memberships rather than spatie roles, and beam-facade 156 for how host-defined roles arrive with no
 * change on this side.
 */
class RolesRecipientKind implements RecipientKind
{
    public function __construct(protected AccountsDirectory $directory) {}

    public function resolve(array $selectors, array $context): array
    {
        $recipients = [];

        foreach ($selectors as $role) {
            foreach ($this->directory->membersOfRole((string) $role) as $member) {
                $recipients[] = Recipient::notifiable($member);
            }
        }

        return $recipients;
    }
}
