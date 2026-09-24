<?php

declare(strict_types=1);

namespace Splicewire\Beam\Accounts\Data\Pages;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Accounts\Teams\InvitationRedemption;

/**
 * The props of the `auth/accept-invitation` Inertia page (`invitations.accept`).
 *
 * `state` is {@see InvitationRedemption}'s verdict on the link for THIS viewer — one of `ready`, `guest`,
 * `wrong-account`, `expired`, `used`, `invalid`. The team, address and role are carried only when the link
 * resolved to a live invitation: an invalid or tampered link names nothing, so a guessed token learns no
 * team name.
 *
 * `acceptUrl` is the `redeem` operation's URL (present only when the viewer can redeem); `registerUrl` /
 * `loginUrl` / `logoutUrl` are the host's Fortify routes when it mounts them, so the page never offers a
 * door the host does not have.
 */
#[TypeScript]
final class AcceptInvitationPageData extends Data
{
    public function __construct(
        public string $state,
        public ?string $teamName = null,
        public ?string $email = null,
        public ?string $role = null,
        public ?string $inviterName = null,
        public ?string $expiresAt = null,
        public ?string $viewerEmail = null,
        public ?string $acceptUrl = null,
        public ?string $registerUrl = null,
        public ?string $loginUrl = null,
        public ?string $logoutUrl = null,
    ) {}
}
