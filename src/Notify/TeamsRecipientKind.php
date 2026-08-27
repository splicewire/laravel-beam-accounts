<?php

namespace Splicewire\Beam\Accounts\Notify;

use Splicewire\Beam\Notifications\Contracts\AccountsDirectory;
use Splicewire\Beam\Notifications\Contracts\RecipientKind;
use Splicewire\Beam\Notifications\Recipients\Recipient;

/**
 * The `to_teams:` recipient kind — every member of the named teams, as a persistent Notifiable.
 *
 * A selector is a team SLUG, not a key: the value is authored into a JSON Schema that travels between
 * hosts, where an auto-increment id means nothing (beam-facade 100 D5). Slugs are globally unique, so
 * one selector names at most one team.
 *
 * No role filter — a team selector means the whole team. Narrowing to "the admins of team X" is the
 * `in_team:` scope MODIFIER, which is the declared growth path and is deliberately not built here: it
 * is spelled `in_` rather than `to_` because it contributes nobody by itself.
 */
class TeamsRecipientKind implements RecipientKind
{
    public function __construct(protected AccountsDirectory $directory) {}

    public function resolve(array $selectors, array $context): array
    {
        $recipients = [];

        foreach ($selectors as $team) {
            foreach ($this->directory->membersOfTeam((string) $team) as $member) {
                $recipients[] = Recipient::notifiable($member);
            }
        }

        return $recipients;
    }
}
